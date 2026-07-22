<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\BlueprintValidator;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * Notifications — an applied transition fires the structured WorkflowTransitioned event (for code
 * listeners) and runs the transition's named EFFECTS (the notifier catalog). Effects are referenced
 * by name, validated against the catalog, param-fed, and isolated (a throwing effect never rolls
 * back the committed transition).
 */

class EffectTicket extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'effect_tickets';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'effect-ticket';
    }
}

function effectBlueprint(array $effects = [], array $effectParams = []): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'effect.lifecycle',
        'places' => ['open', 'closed'],
        'initial' => ['open'],
        'transitions' => [[
            'name' => 'close',
            'from' => 'open',
            'to' => 'closed',
            'effects' => $effects,
            'metadata' => $effectParams ? ['effect_params' => $effectParams] : [],
        ]],
    ]);
}

beforeEach(function () {
    if (! Schema::hasTable('effect_tickets')) {
        Schema::create('effect_tickets', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('open');
            $table->uuid('workflow_version')->nullable();
        });
    }
});

it('fires the structured WorkflowTransitioned event on an applied transition', function () {
    app(DefinitionStore::class)->ensureSystemLineage('effect.lifecycle', 'Effect Lifecycle', effectBlueprint());
    app(WorkflowBindingRegistry::class)->bind('effect-ticket', 'effect.lifecycle');

    $captured = null;
    Event::listen(WorkflowTransitioned::class, function (WorkflowTransitioned $e) use (&$captured) {
        $captured = $e;
    });

    $ticket = EffectTicket::create(['status' => 'open']);
    app(LifecycleService::class)->transition($ticket, 'close');

    expect($captured)->toBeInstanceOf(WorkflowTransitioned::class)
        ->and($captured->transition)->toBe('close')
        ->and($captured->from)->toBe(['open'])
        ->and($captured->to)->toBe(['closed'])
        ->and($captured->subject->is($ticket))->toBeTrue();
});

it('threads the opaque actor token from the TransitionContext onto the event', function () {
    app(DefinitionStore::class)->ensureSystemLineage('effect.lifecycle', 'Effect Lifecycle', effectBlueprint());
    app(WorkflowBindingRegistry::class)->bind('effect-ticket', 'effect.lifecycle');

    $captured = null;
    Event::listen(WorkflowTransitioned::class, function (WorkflowTransitioned $e) use (&$captured) {
        $captured = $e;
    });

    // The host stamps an opaque `kind:selector` token; the engine forwards it verbatim, never resolving.
    app(LifecycleService::class)->transition(
        EffectTicket::create(['status' => 'open']),
        'close',
        context: new TransitionContext(actor: 'user:42', runId: 'ctx-run'),
    );

    expect($captured->actor)->toBe('user:42')
        ->and($captured->runId)->toBe('ctx-run');
});

it('leaves the actor null when no context is supplied (a system/queue path)', function () {
    app(DefinitionStore::class)->ensureSystemLineage('effect.lifecycle', 'Effect Lifecycle', effectBlueprint());
    app(WorkflowBindingRegistry::class)->bind('effect-ticket', 'effect.lifecycle');

    $captured = null;
    Event::listen(WorkflowTransitioned::class, function (WorkflowTransitioned $e) use (&$captured) {
        $captured = $e;
    });

    app(LifecycleService::class)->transition(EffectTicket::create(['status' => 'open']), 'close');

    expect($captured->actor)->toBeNull();
});

it('runs a transition effect with its author-set params', function () {
    $seen = [];
    app(TransitionEffectRegistry::class)->register(
        'notify_watchers',
        function (WorkflowTransitioned $e, array $params) use (&$seen) {
            $seen = ['transition' => $e->transition, 'audience' => $params['audience'] ?? null];
        },
        label: 'Notify watchers',
    );

    app(DefinitionStore::class)->ensureSystemLineage('effect.lifecycle', 'Effect Lifecycle',
        effectBlueprint(effects: ['notify_watchers'], effectParams: ['notify_watchers' => ['audience' => 'editors']]));
    app(WorkflowBindingRegistry::class)->bind('effect-ticket', 'effect.lifecycle');

    app(LifecycleService::class)->transition(EffectTicket::create(['status' => 'open']), 'close');

    expect($seen)->toBe(['transition' => 'close', 'audience' => 'editors']);
});

it('isolates a throwing effect — the transition still commits', function () {
    app(TransitionEffectRegistry::class)->register('boom', function () {
        throw new RuntimeException('effect blew up');
    });

    app(DefinitionStore::class)->ensureSystemLineage('effect.lifecycle', 'Effect Lifecycle', effectBlueprint(effects: ['boom']));
    app(WorkflowBindingRegistry::class)->bind('effect-ticket', 'effect.lifecycle');

    $ticket = EffectTicket::create(['status' => 'open']);
    $result = app(LifecycleService::class)->transition($ticket, 'close');

    // The Control change committed despite the effect throwing (fire-and-forget).
    expect($result->applied)->toBeTrue()
        ->and($ticket->fresh()->status)->toBe('closed');
});

it('rejects a blueprint referencing an unknown effect at validation', function () {
    $validator = app(BlueprintValidator::class);

    expect(fn () => $validator->validate(effectBlueprint(effects: ['ghost_effect'])))
        ->toThrow(InvalidArgumentException::class, 'not in the effect catalog');
});
