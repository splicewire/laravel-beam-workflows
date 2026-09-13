<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Contracts\ProvidesGuardContext;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class ActionArticle extends Model implements ProvidesGuardContext, WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'action_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'action-article';
    }

    public function workflowGuardContext(): array
    {
        return ['reviewed' => (bool) $this->reviewed];
    }
}

beforeEach(function () {
    Schema::create('action_articles', function (TableBlueprint $table) {
        $table->increments('id');
        $table->string('status')->default('draft');
        $table->boolean('reviewed')->default(false);
        $table->uuid('workflow_version')->nullable();
    });
    app(DefinitionStore::class)->ensureSystemLineage('action-article.lifecycle', 'Article Lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'action-article.lifecycle', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'reviewed']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('action-article', 'action-article.lifecycle');
    app(GuardRegistry::class)->register('reviewed', fn (object $s): bool|string => ! empty($s->context['reviewed']) ? true : 'Review is required.');
    app(SubjectResolverRegistry::class)->register('article', fn (string $id) => ActionArticle::find($id));
});

it('prepares and executes an authorized pinned action once per durable identity', function () {
    app()->bind(Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority::class, fn () => new class implements Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority
    {
        public function authorize(Model $subject, string $transition, Splicewire\Beam\Workflows\Actions\WorkflowActionContext $context): void
        {
            if ($context->principal !== 'user:allowed' || $context->tenantToken !== 'tenant:test') {
                throw new Illuminate\Auth\Access\AuthorizationException('The execution principal is not authorized.');
            }
        }
    });
    $article = ActionArticle::create(['status' => 'draft', 'reviewed' => true]);
    $service = app(Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    $context = new Splicewire\Beam\Workflows\Actions\WorkflowActionContext('user:allowed', 'user:creator', 'tenant:test');
    $data = $service->prepare(new Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('article', (string) $article->id, 'publish'), $context);
    expect($data->definitionVersion)->not->toBeNull();
    $first = $service->execute('calendar:first-attempt', $data, $context);
    $replayed = $service->execute('calendar:first-attempt', $data, $context);
    expect($first->applied)->toBeTrue()
        ->and($replayed->transitionId)->toBe($first->transitionId)
        ->and(app(Splicewire\Beam\Workflows\Control\WorkflowHistory::class)->forSubject($article))->toHaveCount(1);
});

function actionAuthority(bool $allowed = true): object
{
    $authority = new class($allowed) implements Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority
    {
        public function __construct(public bool $allowed) {}

        public function authorize(Model $subject, string $transition, Splicewire\Beam\Workflows\Actions\WorkflowActionContext $context): void
        {
            if (! $this->allowed || $context->principal !== 'user:allowed' || $context->tenantToken !== 'tenant:test') {
                throw new Illuminate\Auth\Access\AuthorizationException('The execution principal is not authorized.');
            }
        }
    };
    app()->instance(Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority::class, $authority);

    return $authority;
}

it('refuses revoked execution authority and a migrated definition without applying', function () {
    $authority = actionAuthority();
    $article = ActionArticle::create(['status' => 'draft', 'reviewed' => true]);
    $service = app(Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    $context = new Splicewire\Beam\Workflows\Actions\WorkflowActionContext('user:allowed', 'user:creator', 'tenant:test');
    $data = $service->prepare(new Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('article', (string) $article->id, 'publish'), $context);
    $authority->allowed = false;
    expect($service->execute('denied-attempt', $data, $context)->applied)->toBeFalse();
    $authority->allowed = true;
    $article->refresh()->update(['workflow_version' => '00000000-0000-0000-0000-000000000001']);
    expect($service->execute('migrated-attempt', $data, $context)->blockers[0])->toContain('definition changed')
        ->and($article->fresh()->status)->toBe('draft');
});

it('retains refusal history and requires a new identity for an explicit retry', function () {
    actionAuthority();
    $article = ActionArticle::create(['status' => 'draft', 'reviewed' => false]);
    $service = app(Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    $context = new Splicewire\Beam\Workflows\Actions\WorkflowActionContext('user:allowed', 'user:creator', 'tenant:test');
    $data = $service->prepare(new Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('article', (string) $article->id, 'publish'), $context);
    expect($service->execute('blocked-attempt', $data, $context)->blockers)->toBe(['Review is required.']);
    $article->refresh()->update(['reviewed' => true]);
    expect($service->execute('blocked-attempt', $data, $context)->applied)->toBeFalse()
        ->and($service->execute('explicit-retry', $data, $context)->applied)->toBeTrue();
});

it('recovers the same identity after outer rollback and rejects identity substitution', function () {
    actionAuthority();
    $article = ActionArticle::create(['status' => 'draft', 'reviewed' => true]);
    $service = app(Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    $context = new Splicewire\Beam\Workflows\Actions\WorkflowActionContext('user:allowed', 'user:creator', 'tenant:test');
    $data = $service->prepare(new Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('article', (string) $article->id, 'publish'), $context);
    $article->getConnection()->beginTransaction();
    expect($service->execute('crash-attempt', $data, $context)->applied)->toBeTrue();
    $article->getConnection()->rollBack();
    expect($article->fresh()->status)->toBe('draft')
        ->and($service->execute('crash-attempt', $data, $context)->applied)->toBeTrue();
    $data->subjectId = 'another';
    expect(fn () => $service->execute('crash-attempt', $data, $context))
        ->toThrow(LogicException::class, 'different request');
});

it('rejects an unknown transition before leaving a pin or executable request', function () {
    actionAuthority();
    $article = ActionArticle::create(['status' => 'draft', 'reviewed' => false]);
    $service = app(Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    $context = new Splicewire\Beam\Workflows\Actions\WorkflowActionContext('user:allowed', 'user:creator', 'tenant:test');
    expect(fn () => $service->prepare(new Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('article', (string) $article->id, 'teleport'), $context))
        ->toThrow(InvalidArgumentException::class, 'not declared');
    expect($article->fresh()->workflow_version)->toBeNull();
});
