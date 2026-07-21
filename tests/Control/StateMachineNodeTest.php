<?php

use Rushing\Popcorn\InvocableRegistry;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowApplyInvocable;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\CircuitEngine\Dispatch\CapabilityDispatcher;
use Splicewire\CircuitSpineData\Context\RunContext;
use Splicewire\CircuitSpineData\Graph\Node;
use Splicewire\CircuitSpineData\Ports\Envelope;
use Splicewire\CircuitSpineData\Ports\Port;

/*
 * Seam B behavioral tests — run a state-machine node through the kernel's REAL capability-dispatch
 * path (CapabilityDispatcher → InvocableRegistry → the registered `workflow.apply` invocable →
 * output Port validation). Assert observable behavior (advanced marking, emitted status, rejected
 * illegal transition), never symfony/workflow internals.
 */

function lifecycleBlueprint(): array
{
    return [
        'name' => 'composition.lifecycle',
        'places' => ['draft', 'review', 'published'],
        'initial' => ['draft'],
        'transitions' => [
            ['name' => 'submit_for_review', 'from' => 'draft', 'to' => 'review', 'guard' => 'no_stale_cells'],
            ['name' => 'publish', 'from' => 'review', 'to' => 'published'],
        ],
    ];
}

/**
 * Dispatch the workflow node once through the kernel, with `{ marking, event }` flowing in as an
 * upstream envelope. Returns the validated output Envelope.
 */
function dispatchWorkflowNode(array $config, array $upstream): Envelope
{
    $out = WorkflowApplyInvocable::outputPortSchema();

    $node = new Node(
        ref: 'lifecycle-gate',
        capability: 'workflow.apply',
        config: $config,
        output: new Port($out['type'], $out['schema']),
    );

    $inputs = ['prev' => new Envelope('workflow.marking', $upstream)];

    return app(CapabilityDispatcher::class)->dispatch($node, $inputs, new RunContext('run-1'));
}

it('registers the state-machine node into the kernel capability registry', function () {
    expect(app(InvocableRegistry::class)->has('workflow.apply'))->toBeTrue();
});

it('advances the marking on a legal transition through capability dispatch', function () {
    app(GuardRegistry::class)->register('no_stale_cells', fn ($subject) => true);

    $envelope = dispatchWorkflowNode(
        config: ['definition' => lifecycleBlueprint()],
        upstream: ['marking' => ['draft'], 'event' => 'submit_for_review'],
    );

    expect($envelope->type)->toBe('workflow.marking')
        ->and($envelope->payload['applied'])->toBeTrue()
        ->and($envelope->payload['marking'])->toBe(['review'])
        ->and($envelope->payload['transition'])->toBe('submit_for_review')
        ->and($envelope->payload['blockers'])->toBe([]);
});

it('emits a StatusEvent on each applied transition (Control change auto-produces Display history)', function () {
    app(GuardRegistry::class)->register('no_stale_cells', fn ($subject) => true);

    dispatchWorkflowNode(
        config: ['definition' => lifecycleBlueprint(), 'run_id' => 'run-42'],
        upstream: ['marking' => ['draft'], 'event' => 'submit_for_review'],
    );

    $row = Activity::latest('id')->first();

    expect($row->log_name)->toBe('status')
        ->and($row->event)->toBe('running')
        ->and($row->description)->toContain('submit_for_review')
        ->and($row->properties['run_id'])->toBe('run-42');
});

it('rejects a guarded transition without applying, surfacing the reason', function () {
    app(GuardRegistry::class)->register(
        'no_stale_cells',
        fn ($subject) => empty($subject->context['stale_cells']) ? true : 'Cannot submit: 2 cells are stale.',
    );

    $envelope = dispatchWorkflowNode(
        config: ['definition' => lifecycleBlueprint()],
        upstream: ['marking' => ['draft'], 'event' => 'submit_for_review', 'context' => ['stale_cells' => 2]],
    );

    expect($envelope->payload['applied'])->toBeFalse()
        ->and($envelope->payload['marking'])->toBe(['draft']) // NOT mutated
        ->and($envelope->payload['blockers'])->toContain('Cannot submit: 2 cells are stale.');
});

it('rejects an illegal transition (not enabled by the current marking) without applying', function () {
    app(GuardRegistry::class)->register('no_stale_cells', fn ($subject) => true);

    // `publish` is only legal from `review`; from `draft` it is not enabled.
    $envelope = dispatchWorkflowNode(
        config: ['definition' => lifecycleBlueprint()],
        upstream: ['marking' => ['draft'], 'event' => 'publish'],
    );

    expect($envelope->payload['applied'])->toBeFalse()
        ->and($envelope->payload['marking'])->toBe(['draft'])
        ->and($envelope->payload['blockers'])->not->toBeEmpty();
});

it('is stateless across a pause: resumes at the same frontier from the re-hydrated marking', function () {
    app(GuardRegistry::class)->register('no_stale_cells', fn ($subject) => true);

    // First advance parks the run at `review` (a NeedsReview gate).
    $paused = dispatchWorkflowNode(
        config: ['definition' => lifecycleBlueprint()],
        upstream: ['marking' => ['draft'], 'event' => 'submit_for_review'],
    );
    expect($paused->payload['marking'])->toBe(['review']);

    // …human approves… the host re-hydrates the marking from its projection and resumes. The node
    // held nothing across the pause — feeding the persisted marking back advances the next step.
    $resumed = dispatchWorkflowNode(
        config: ['definition' => lifecycleBlueprint()],
        upstream: ['marking' => $paused->payload['marking'], 'event' => 'publish'],
    );

    expect($resumed->payload['applied'])->toBeTrue()
        ->and($resumed->payload['marking'])->toBe(['published']);
});

it('carries workflow-net multi-place markings as a list through the node', function () {
    app(WorkflowRegistry::class)->register('fork.join', [
        'name' => 'fork.join',
        'places' => ['start', 'a', 'b', 'done'],
        'initial' => ['start'],
        'transitions' => [
            ['name' => 'fork', 'from' => 'start', 'to' => ['a', 'b']],
            ['name' => 'join', 'from' => ['a', 'b'], 'to' => 'done'],
        ],
    ]);

    $forked = dispatchWorkflowNode(
        config: ['definition' => 'fork.join'],
        upstream: ['marking' => ['start'], 'event' => 'fork'],
    );

    // A list of BOTH places, not a scalar.
    expect($forked->payload['marking'])->toEqualCanonicalizing(['a', 'b']);

    $joined = dispatchWorkflowNode(
        config: ['definition' => 'fork.join'],
        upstream: ['marking' => $forked->payload['marking'], 'event' => 'join'],
    );

    expect($joined->payload['marking'])->toBe(['done']);
});
