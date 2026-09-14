<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionScheduler;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowHistory;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class CalendarWorkflowArticle extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'calendar_workflow_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'calendar-article';
    }
}

beforeEach(function () {
    Schema::create('calendar_workflow_articles', function (Blueprint $table) {
        $table->id();
        $table->string('status')->default('draft');
        $table->uuid('workflow_version')->nullable();
    });
    app(DefinitionStore::class)->ensureSystemLineage('calendar-article', 'Calendar Article', WorkflowBlueprint::fromArray([
        'name' => 'calendar-article', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('calendar-article', 'calendar-article');
    app(SubjectResolverRegistry::class)->register('article', fn ($id) => CalendarWorkflowArticle::find($id));
    app()->bind(WorkflowActionAuthority::class, fn () => new class implements WorkflowActionAuthority
    {
        public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void
        {
            if ($context->principal !== 'user:editor' || $context->tenantToken !== 'tenant:test') {
                throw new Illuminate\Auth\Access\AuthorizationException('Principal or tenant unavailable.');
            }
        }
    });
});

it('executes a standalone dated workflow action through the real optional handler and due runner', function () {
    $article = CalendarWorkflowArticle::create(['status' => 'draft']);
    $actions = app(ActionService::class);
    $action = $actions->schedule(new CalendarActionData(
        'kind.workflow-transition', ['subject_kind' => 'article', 'subject_id' => (string) $article->id, 'transition' => 'publish'],
        '2026-09-18T09:00:00-04:00', 'America/New_York',
    ), new ActionContext('user:editor', 'user:creator', 'tenant:test'));
    expect($action->payload['definition_version'])->not->toBeNull();
    $runner = app(ActionScheduler::class);
    expect($runner->run($action->id, 'tenant:other', CarbonImmutable::parse('2026-09-18T13:00:00Z')))->toBeNull()
        ->and($runner->run($action->id, 'tenant:test', CarbonImmutable::parse('2026-09-18T12:59:00Z')))->toBeNull();
    $attempt = $runner->run($action->id, 'tenant:test', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    expect($attempt->status)->toBe('applied')
        ->and($article->fresh()->status)->toBe('published')
        ->and($attempt->result['transition_id'])->toBe(app(WorkflowHistory::class)->forSubject($article)->first()->id)
        ->and($runner->run($action->id, 'tenant:test', CarbonImmutable::parse('2026-09-18T14:00:00Z')))->toBeNull();
});

it('keeps standalone workflow intent idempotent and recovers a crashed local transaction', function () {
    $article = CalendarWorkflowArticle::create(['status' => 'draft']);
    $actions = app(ActionService::class);
    $request = new CalendarActionData(
        'kind.workflow-transition', ['subject_kind' => 'article', 'subject_id' => (string) $article->id, 'transition' => 'publish'],
        '2026-09-18T09:00:00-04:00', 'America/New_York', origin: 'standalone:article:'.$article->id,
    );
    $context = new ActionContext('user:editor', 'user:creator', 'tenant:test');
    $action = $actions->schedule($request, $context);
    expect($actions->schedule($request, $context)->id)->toBe($action->id);

    $connection = DB::connection();
    expect(fn () => $connection->transaction(function () use ($action, $connection): void {
        app(ActionScheduler::class)->run($action->id, 'tenant:test', CarbonImmutable::parse('2026-09-18T13:00:00Z'), $connection);
        throw new RuntimeException('Abort standalone action before commit.');
    }))->toThrow(RuntimeException::class, 'Abort standalone action before commit.');

    expect($action->fresh()->status)->toBe('pending')->and($article->fresh()->status)->toBe('draft');
    $attempt = app(ActionScheduler::class)->run($action->id, 'tenant:test', CarbonImmutable::parse('2026-09-18T13:00:00Z'));
    expect($attempt->status)->toBe('applied')->and($article->fresh()->status)->toBe('published');
});
