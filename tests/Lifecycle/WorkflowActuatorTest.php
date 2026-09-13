<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Contracts\ProvidesGuardContext;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * The generic actuation surface — a host registers a resolver per subject `kind`, and the actuator
 * drives ANY managed record's lifecycle (resolve → projection → transition), pulling the record's
 * own guard context. Proven on a non-composition `Article` with a stale-review gate.
 */

class ActuatorArticle extends Model implements ProvidesGuardContext, WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'actuator_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'article';
    }

    public function workflowGuardContext(): array
    {
        return ['reviewed' => (bool) $this->reviewed];
    }
}

beforeEach(function () {
    if (! Schema::hasTable('actuator_articles')) {
        Schema::create('actuator_articles', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('draft');
            $table->boolean('reviewed')->default(false);
            $table->uuid('workflow_version')->nullable();
        });
    }

    app(DefinitionStore::class)->ensureSystemLineage('article.lifecycle', 'Article Lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'article.lifecycle',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'reviewed']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('article', 'article.lifecycle');
    app(GuardRegistry::class)->register(
        'reviewed',
        fn (object $s): bool|string => ! empty($s->context['reviewed']) ? true : 'The article must be reviewed before publishing.',
    );

    // The host wires the subject kind in ONE line — no per-model endpoint.
    app(SubjectResolverRegistry::class)->register('article', fn (string $id) => ActuatorArticle::find($id));
});

it('resolves a subject by kind + id', function () {
    $article = ActuatorArticle::create(['status' => 'draft']);
    $actuator = app(WorkflowActuator::class);

    expect($actuator->subject('article', (string) $article->id)?->is($article))->toBeTrue()
        ->and($actuator->subject('article', '999'))->toBeNull()
        ->and($actuator->subject('unregistered-kind', '1'))->toBeNull();
});

it('projects and transitions a resolved record, pulling its own guard context', function () {
    $article = ActuatorArticle::create(['status' => 'draft', 'reviewed' => false]);
    $actuator = app(WorkflowActuator::class);

    // Not reviewed → the guard (fed from the record's own context) blocks publish.
    $blocked = $actuator->transition($article, 'publish');
    expect($blocked->applied)->toBeFalse()
        ->and($blocked->blockers[0])->toContain('reviewed')
        ->and($article->fresh()->status)->toBe('draft');

    // Reviewed → the same call now succeeds; the projection reports the article's type + marking.
    $article->update(['reviewed' => true]);
    expect($actuator->projection($article)['type'])->toBe('article')
        ->and($actuator->available($article))->toBe(['publish']);

    $ok = $actuator->transition($article, 'publish');
    expect($ok->applied)->toBeTrue()
        ->and($article->fresh()->status)->toBe('published');
});

it('evaluates the persisted subject and guard context instead of an old caller snapshot', function () {
    $article = ActuatorArticle::create(['status' => 'draft', 'reviewed' => true]);
    $stale = $article->fresh();
    $article->update(['reviewed' => false]);

    $result = app(WorkflowActuator::class)->transition($stale, 'publish');

    expect($result->applied)->toBeFalse()
        ->and($article->fresh()->status)->toBe('draft');
});

it('does not reapply a transition from a stale marking after another caller won', function () {
    $article = ActuatorArticle::create(['status' => 'draft', 'reviewed' => true]);
    $stale = $article->fresh();
    $actuator = app(WorkflowActuator::class);

    expect($actuator->transition($article, 'publish')->applied)->toBeTrue()
        ->and($actuator->transition($stale, 'publish')->applied)->toBeFalse();
});

it('freezes a code-only definition and preserves its immutable pin', function () {
    $blueprint = WorkflowBlueprint::fromArray([
        'name' => 'article.code', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]);
    app(Splicewire\Beam\Workflows\Control\WorkflowRegistry::class)->register('article.code', $blueprint);
    app(WorkflowBindingRegistry::class)->bind('article', 'article.code');
    $article = ActuatorArticle::create(['status' => 'draft']);
    $lifecycle = app(Splicewire\Beam\Workflows\Control\LifecycleService::class);
    $pin = $lifecycle->pinDefinition($article);

    expect($pin)->not->toBeNull()
        ->and($article->fresh()->workflow_version)->toBe($pin)
        ->and(app(DefinitionStore::class)->version($pin)->toBlueprint()->toArray())->toBe($blueprint->toArray())
        ->and($lifecycle->pinDefinition($article))->toBe($pin);
});

it('refuses a broken existing pin instead of silently adopting another definition', function () {
    $article = ActuatorArticle::create(['status' => 'draft', 'reviewed' => true,
        'workflow_version' => '00000000-0000-0000-0000-000000000001']);

    expect(app(WorkflowActuator::class)->transition($article, 'publish')->applied)->toBeFalse()
        ->and($article->fresh()->workflow_version)->toBe('00000000-0000-0000-0000-000000000001');
});

it('keeps an explicitly connected subjects definition status and facts on that connection', function () {
    config(['database.connections.workflow_other' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]);
    $blueprint = WorkflowBlueprint::fromArray([
        'name' => 'article.other', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]);
    app('db')->usingConnection('workflow_other', function () use ($blueprint) {
        $this->createDefinitionStoreTables();
        (require __DIR__.'/../../database/migrations/tenant/create_workflow_transition_facts_table.php.stub')->up();
        Schema::create('actuator_articles', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('draft');
            $table->boolean('reviewed')->default(false);
            $table->uuid('workflow_version')->nullable();
        });
        app(DefinitionStore::class)->ensureSystemLineage('article.other', 'Other Article', $blueprint);
    });
    app(WorkflowBindingRegistry::class)->bind('article', 'article.other');
    $defaultArticle = ActuatorArticle::create(['status' => 'draft']);
    $otherArticle = ActuatorArticle::on('workflow_other')->create(['status' => 'draft']);
    $actuator = app(WorkflowActuator::class);
    $history = app(Splicewire\Beam\Workflows\Control\WorkflowHistory::class);

    $otherArticle->getConnection()->beginTransaction();
    expect($actuator->transition($otherArticle, 'publish')->applied)->toBeTrue();
    $otherArticle->getConnection()->rollBack();
    expect($otherArticle->fresh()->status)->toBe('draft')
        ->and($history->forSubject($otherArticle))->toHaveCount(0);

    expect($actuator->transition($otherArticle, 'publish')->applied)->toBeTrue()
        ->and($history->forSubject($otherArticle))->toHaveCount(1)
        ->and($otherArticle->fresh()->workflow_version)->not->toBeNull()
        ->and($defaultArticle->fresh()->status)->toBe('draft')
        ->and($history->forSubject($defaultArticle))->toHaveCount(0)
        ->and(app(DefinitionStore::class)->lineageByKey('article.other'))->toBeNull();
});

