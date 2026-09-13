<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Actions\WorkflowActionService;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Circuit\WorkflowCircuitExecution;
use Splicewire\Beam\Workflows\Circuit\WorkflowCircuitExecutionScope;
use Splicewire\Beam\Workflows\Circuit\WorkflowSubjectInvocable;
use Splicewire\Beam\Workflows\Control\Contracts\ProvidesGuardContext;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowHistory;
use Splicewire\Beam\Workflows\Control\WorkflowInvocableRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;
use Splicewire\Circuits\Context\RunContext;
use Splicewire\Circuits\Dispatch\CapabilityDispatcher;
use Splicewire\Circuits\Graph\Node;

class CircuitActionArticle extends Model implements ProvidesGuardContext, WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'circuit_action_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'circuit-article';
    }

    public function workflowGuardContext(): array
    {
        return ['reviewed' => (bool) $this->reviewed];
    }
}

beforeEach(function () {
    Schema::create('circuit_action_articles', function (TableBlueprint $table) {
        $table->increments('id');
        $table->string('status')->default('draft');
        $table->boolean('reviewed')->default(false);
        $table->uuid('workflow_version')->nullable();
    });
    app(DefinitionStore::class)->ensureSystemLineage('circuit-article.lifecycle', 'Circuit Article', WorkflowBlueprint::fromArray([
        'name' => 'circuit-article.lifecycle', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [
            ['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'circuit_reviewed'],
            ['name' => 'revise', 'from' => 'published', 'to' => 'draft'],
        ],
    ]));
    app(WorkflowBindingRegistry::class)->bind('circuit-article', 'circuit-article.lifecycle');
    app(GuardRegistry::class)->register('circuit_reviewed', fn (object $subject): bool|string => ! empty($subject->context['reviewed']) ? true : 'Review is required.');
    app(SubjectResolverRegistry::class)->register('circuit-article', fn (string $id) => CircuitActionArticle::find($id));
    app()->instance(WorkflowActionAuthority::class, new class implements WorkflowActionAuthority
    {
        public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void
        {
            if ($context->principal !== 'user:allowed' || $context->tenantToken !== 'tenant:test') {
                throw new AuthorizationException('Circuit principal is not authorized.');
            }
        }
    });
});

it('rejects forged invocation metadata when no trusted node visit is active', function () {
    $scope = new WorkflowCircuitExecutionScope;
    $invocable = new WorkflowSubjectInvocable(app(WorkflowActionService::class), $scope);

    expect(fn () => $invocable->invoke([
        'subject_kind' => 'circuit-article', 'subject_id' => '1', 'transition' => 'publish',
        '_circuit' => ['context' => ['run_id' => 'forged', 'actor' => 'user:allowed']],
    ]))->toThrow(LogicException::class, 'trusted Circuit node visit');
});

function circuitSubjectExecution(CircuitActionArticle $article, string $visit, string $transition = 'publish'): WorkflowCircuitExecution
{
    $context = new WorkflowActionContext('user:allowed', 'user:creator', 'tenant:test', 'run-real');
    $request = app(WorkflowActionService::class)->prepare(new WorkflowActionData('circuit-article', (string) $article->id, $transition), $context, $article->getConnection());

    return new WorkflowCircuitExecution($visit, $request, $context, $article->getConnection());
}

function circuitSubjectNode(CircuitActionArticle $article, string $transition = 'publish'): Node
{
    return new Node('change-subject', WorkflowSubjectInvocable::NAME, [
        'subject_kind' => 'circuit-article', 'subject_id' => (string) $article->id, 'transition' => $transition,
    ]);
}

it('rejects substitution of the durably prepared subject request', function () {
    $article = CircuitActionArticle::create(['reviewed' => true]);
    $scope = new WorkflowCircuitExecutionScope;
    $invocable = new WorkflowSubjectInvocable(app(WorkflowActionService::class), $scope);
    $execution = circuitSubjectExecution($article, 'node-visit-1');

    expect(fn () => $scope->run($execution, fn () => $invocable->invoke([
        'subject_kind' => 'circuit-article', 'subject_id' => 'other', 'transition' => 'publish',
    ])))->toThrow(LogicException::class, 'prepared request');
    expect($article->fresh()->status)->toBe('draft');
});

it('marks a refused subject action failed and skips its success-dependent node', function () {
    $article = CircuitActionArticle::create(['reviewed' => false]);
    $scope = new WorkflowCircuitExecutionScope;
    app(WorkflowInvocableRegistry::class)->register(new WorkflowSubjectInvocable(app(WorkflowActionService::class), $scope));
    $graph = new Splicewire\Circuits\Execution\Graph([
        circuitSubjectNode($article),
        new Node('distribute', 'unregistered.downstream'),
    ], [new Splicewire\Circuits\Graph\Edge('change-subject', 'distribute')]);

    $run = $scope->run(circuitSubjectExecution($article, 'blocked-node-visit'), fn () => app(Splicewire\Circuits\Scheduling\CyclicScheduler::class)->run($graph, new RunContext('run-real')));

    expect($run->latestFor('change-subject')->status)->toBe(Splicewire\Circuits\Run\NodeRunStatus::Failed)
        ->and($run->latestFor('change-subject')->error)->toContain('Review is required.')
        ->and($run->latestFor('distribute')->status)->toBe(Splicewire\Circuits\Run\NodeRunStatus::Skipped)
        ->and($article->fresh()->status)->toBe('draft');
});

it('dispatches a typed persisted subject action and replays the same visit after the subject migrates', function () {
    app()->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
    $article = CircuitActionArticle::create(['reviewed' => true]);
    $scope = new WorkflowCircuitExecutionScope;
    app(WorkflowInvocableRegistry::class)->register(new WorkflowSubjectInvocable(app(WorkflowActionService::class), $scope));
    $execution = circuitSubjectExecution($article, 'node-visit-durable');
    $node = circuitSubjectNode($article);
    $inputSchema = WorkflowSubjectInvocable::inputPortSchema();
    app(Splicewire\Circuits\Validation\Contracts\PortValidator::class)->validate(
        new Splicewire\Circuits\Ports\Envelope($inputSchema['type'], $node->config),
        new Splicewire\Circuits\Ports\Port($inputSchema['type'], $inputSchema['schema']),
    );
    $schema = WorkflowSubjectInvocable::outputPortSchema();
    $node->output = new Splicewire\Circuits\Ports\Port($schema['type'], $schema['schema']);
    $dispatcher = app(CapabilityDispatcher::class);
    $invoke = fn () => $dispatcher->dispatch($node, [], new RunContext('forged-run', actor: 'user:forged'));

    $first = $scope->run($execution, $invoke);
    $article->refresh()->update(['workflow_version' => '00000000-0000-0000-0000-000000000001']);
    $replayed = $scope->run($execution, $invoke);
    $facts = app(WorkflowHistory::class)->forSubject($article);

    expect($first->payload['subject_kind'])->toBe('circuit-article')
        ->and($first->payload['subject_id'])->toBe((string) $article->id)
        ->and($first->payload['applied'])->toBeTrue()
        ->and($replayed->payload['transition_id'])->toBe($first->payload['transition_id'])
        ->and($facts)->toHaveCount(1)
        ->and($facts->first()->run_id)->toBe('run-real')
        ->and($facts->first()->actor)->toBe('user:allowed')
        ->and($article->fresh()->status)->toBe('published');
});

it('uses a distinct durable identity for a later intentional visit to the same node', function () {
    $article = CircuitActionArticle::create(['reviewed' => true]);
    $scope = new WorkflowCircuitExecutionScope;
    $invocable = new WorkflowSubjectInvocable(app(WorkflowActionService::class), $scope);
    $input = circuitSubjectNode($article)->config;
    $firstVisit = circuitSubjectExecution($article, 'node-loop-iteration-0');
    $first = $scope->run($firstVisit, fn () => $invocable->invoke($input));
    app(Splicewire\Beam\Workflows\Control\WorkflowActuator::class)->transition($article->fresh(), 'revise');
    $replay = $scope->run($firstVisit, fn () => $invocable->invoke($input));
    expect($article->fresh()->status)->toBe('draft')
        ->and($replay['payload']['transition_id'])->toBe($first['payload']['transition_id']);

    $next = $scope->run(circuitSubjectExecution($article, 'node-loop-iteration-1'), fn () => $invocable->invoke($input));

    expect($article->fresh()->status)->toBe('published')
        ->and($next['payload']['transition_id'])->not->toBe($first['payload']['transition_id'])
        ->and(app(WorkflowHistory::class)->forSubject($article))->toHaveCount(3);
});

it('clears trusted execution after failure and restores the enclosing visit after nested execution', function () {
    $article = CircuitActionArticle::create(['reviewed' => true]);
    $scope = new WorkflowCircuitExecutionScope;
    $outer = circuitSubjectExecution($article, 'outer-visit');
    $inner = circuitSubjectExecution($article, 'inner-visit');

    $scope->run($outer, function () use ($scope, $outer, $inner) {
        expect(fn () => $scope->run($inner, fn () => throw new RuntimeException('Nested failure.')))->toThrow(RuntimeException::class);
        expect($scope->current()->identity)->toBe($outer->identity);
    });
    expect(fn () => $scope->current())->toThrow(LogicException::class, 'trusted Circuit node visit');
});

it('resumes a terminal kernel node without needing another execution scope or subject action', function () {
    $article = CircuitActionArticle::create(['reviewed' => true]);
    $scope = new WorkflowCircuitExecutionScope;
    app(WorkflowInvocableRegistry::class)->register(new WorkflowSubjectInvocable(app(WorkflowActionService::class), $scope));
    $graph = new Splicewire\Circuits\Execution\Graph([circuitSubjectNode($article)]);
    $scheduler = app(Splicewire\Circuits\Scheduling\CyclicScheduler::class);
    $context = new RunContext('run-real');
    $prior = $scope->run(circuitSubjectExecution($article, 'resumed-node'), fn () => $scheduler->run($graph, $context));

    $resumed = $scheduler->run($graph, $context, resume: $prior);

    expect($resumed->latestFor('change-subject')->status)->toBe(Splicewire\Circuits\Run\NodeRunStatus::Completed)
        ->and(app(WorkflowHistory::class)->forSubject($article))->toHaveCount(1);
});
