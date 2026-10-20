<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Contracts\Activity;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Bridge\DefinitionBuilder;
use Splicewire\Beam\Workflows\Bridge\WorkflowFactory;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\WorkflowRunner;
use Splicewire\Beam\Workflows\Data\WorkflowProjectionData;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * Ticket 04 — the generic LifecycleService, proven on a NON-Composition model (`Ticket`) so the
 * service is demonstrably not composition-shaped. A managed model transitions through its bound,
 * versioned definition; the marking projects onto its status; the version pins; guards block; an
 * unmanaged model is a no-op.
 */

/** A second, non-Composition managed model — a support ticket with its own status/version columns. */
class SupportTicket extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'support_tickets';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'support-ticket';
    }
}

// A governed type carries a morph alias (launch ticket 05 ruling 1), so the fixture does too.
\Illuminate\Database\Eloquent\Relations\Relation::morphMap(['support_ticket' => SupportTicket::class]);

function ticketBlueprint(): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'ticket.lifecycle',
        'places' => ['open', 'triaged', 'closed'],
        'initial' => ['open'],
        'transitions' => [
            ['name' => 'triage', 'from' => 'open', 'to' => 'triaged'],
            ['name' => 'close', 'from' => 'triaged', 'to' => 'closed', 'guard' => 'assignee_present'],
        ],
    ]);
}

beforeEach(function () {
    if (! Schema::hasTable('support_tickets')) {
        Schema::create('support_tickets', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('open');
            $table->uuid('workflow_version')->nullable();
        });
    }

    // Seed the ticket lifecycle into the versioned store + bind the type to it.
    app(DefinitionStore::class)->ensureSystemLineage('ticket.lifecycle', 'Ticket Lifecycle', ticketBlueprint());
    app(WorkflowBindingRegistry::class)->bind('support-ticket', 'ticket.lifecycle', ['assignee' => 'ada']);
    app(GuardRegistry::class)->register(
        'assignee_present',
        fn (object $s): bool|string => ! empty($s->context['assignee']) ? true : 'A ticket needs an assignee before it can close.',
    );
});

it('transitions a non-composition managed model through its bound, versioned definition', function () {
    $ticket = SupportTicket::create(['status' => 'open']);

    $result = app(LifecycleService::class)->transition($ticket, 'triage');

    expect($result->applied)->toBeTrue()
        ->and($ticket->fresh()->status)->toBe('triaged')
        // The version pinned to the active version on first resolution.
        ->and($ticket->fresh()->workflow_version)->not->toBeNull();
});

it('projects the marking and pins the resolved version onto the model', function () {
    $ticket = SupportTicket::create(['status' => 'open']);
    $activeId = app(DefinitionStore::class)->activeVersion('ticket.lifecycle')->id;

    app(LifecycleService::class)->transition($ticket, 'triage');

    expect($ticket->fresh()->workflow_version)->toBe($activeId);
});

it('offers exactly the enabled transitions via available()', function () {
    $ticket = SupportTicket::create(['status' => 'open']);

    expect(app(LifecycleService::class)->available($ticket))->toBe(['triage']);

    app(LifecycleService::class)->transition($ticket, 'triage');

    // From `triaged`, `close` is enabled because the binding supplies an assignee.
    expect(app(LifecycleService::class)->available($ticket->fresh()))->toBe(['close']);
});

it('honours a guard fed from binding params (assignee present)', function () {
    // Re-bind with NO assignee → the close guard vetoes.
    app(WorkflowBindingRegistry::class)->bind('support-ticket', 'ticket.lifecycle', []);

    $ticket = SupportTicket::create(['status' => 'triaged']);
    $result = app(LifecycleService::class)->transition($ticket, 'close');

    expect($result->applied)->toBeFalse()
        ->and($result->blockers[0])->toContain('assignee')
        ->and($ticket->fresh()->status)->toBe('triaged'); // untouched
});

it('keeps a pinned model on its old graph after the definition forks', function () {
    $ticket = SupportTicket::create(['status' => 'open']);
    app(LifecycleService::class)->transition($ticket, 'triage'); // pins to v1

    // The definition is edited: v2 renames the close transition away.
    app(DefinitionStore::class)->fork('ticket.lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'ticket.lifecycle',
        'places' => ['open', 'triaged', 'archived'],
        'initial' => ['open'],
        'transitions' => [['name' => 'archive', 'from' => 'triaged', 'to' => 'archived']],
    ]));

    // The pinned ticket still sees v1's `close`, not v2's `archive`.
    expect(app(LifecycleService::class)->available($ticket->fresh()))->toBe(['close']);
});

it('exposes a model-blind projection (places, transitions, current, available) for the stepper', function () {
    $ticket = SupportTicket::create(['status' => 'open']);

    $projection = app(LifecycleService::class)->projection($ticket);

    expect($projection['type'])->toBe('support-ticket')
        ->and($projection['places'])->toBe(['open', 'triaged', 'closed'])
        ->and($projection['current'])->toBe('open')
        ->and($projection['available'])->toBe(['triage'])
        // Every transition names its guard; `reason` is set only on a refused move out of here.
        ->and($projection['transitions'][0])->toBe(['name' => 'triage', 'from' => ['open'], 'to' => ['triaged'], 'guard' => null, 'reason' => null])
        // `close` is guarded but does not leave `open`: it is no move from here, so it is not "refused".
        ->and($projection['transitions'][1])->toBe(['name' => 'close', 'from' => ['triaged'], 'to' => ['closed'], 'guard' => 'assignee_present', 'reason' => null]);

    // Unmanaged → null projection.
    app(WorkflowBindingRegistry::class)->unbind('support-ticket');
    expect(app(LifecycleService::class)->projection($ticket->fresh()))->toBeNull();
});

it('says why a move out of the current place is refused, in the words a refused attempt returns', function () {
    // Product journey J2: a stepper hid a guarded move with no reason. The binding carries no assignee,
    // so `close` is guarded shut from `triaged`.
    app(WorkflowBindingRegistry::class)->bind('support-ticket', 'ticket.lifecycle', []);
    $ticket = SupportTicket::create(['status' => 'triaged']);

    $projection = app(LifecycleService::class)->projection($ticket);
    $close = collect($projection['transitions'])->firstWhere('name', 'close');

    expect($projection['available'])->toBe([])
        ->and($close['guard'])->toBe('assignee_present')
        ->and($close['reason'])->toBe('A ticket needs an assignee before it can close.');

    // The same sentence the refused attempt answers with: one verdict reader for both.
    $attempt = app(LifecycleService::class)->transition($ticket, 'close');
    expect($attempt->applied)->toBeFalse()
        ->and($attempt->blockers)->toBe([$close['reason']]);

    // The reason crosses the wire on the declared projection shape.
    $wire = WorkflowProjectionData::from($projection)->toArray();
    expect(collect($wire['transitions'])->firstWhere('name', 'close')['reason'])
        ->toBe('A ticket needs an assignee before it can close.');

    // Once the guard admits it, the move is available and nothing is refused.
    app(WorkflowBindingRegistry::class)->bind('support-ticket', 'ticket.lifecycle', ['assignee' => 'ada']);
    $admitted = app(LifecycleService::class)->projection($ticket->fresh());
    expect($admitted['available'])->toBe(['close'])
        ->and(collect($admitted['transitions'])->pluck('reason')->filter()->all())->toBe([]);
});

it('reports an unregistered guard as the refusal, failing closed in the projection as in a transition', function () {
    app(DefinitionStore::class)->ensureSystemLineage('ticket.lifecycle.unguarded', 'Ticket (missing guard)', WorkflowBlueprint::fromArray([
        'name' => 'ticket.lifecycle.unguarded',
        'places' => ['open', 'closed'],
        'initial' => ['open'],
        'transitions' => [['name' => 'close', 'from' => 'open', 'to' => 'closed', 'guard' => 'nobody_registered_me']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('support-ticket', 'ticket.lifecycle.unguarded');
    $ticket = SupportTicket::create(['status' => 'open']);

    $close = collect(app(LifecycleService::class)->projection($ticket)['transitions'])->firstWhere('name', 'close');

    expect($close['reason'])->toBe('Guard [nobody_registered_me] is not registered.');
});

it('says a move the engine refuses with no guard to blame in plain words, never by its machine name', function () {
    // An engine that offers nothing and names no guard: the one refusal the projection cannot attribute to a rule,
    // and a sentence a person reads. It used to embed the transition's name in brackets.
    app()->singleton(WorkflowRunner::class, fn ($app) => new class($app->make(DefinitionBuilder::class), $app->make(WorkflowFactory::class), $app->make(StatusEmitter::class), $app->make(GuardRegistry::class)) extends WorkflowRunner
    {
        public function enabled(WorkflowBlueprint $blueprint, object $subject, string $markingProperty = 'marking'): array
        {
            return [];
        }
    });
    app()->forgetInstance(LifecycleService::class);

    $ticket = SupportTicket::create(['status' => 'open']);
    $triage = collect(app(LifecycleService::class)->projection($ticket)['transitions'])->firstWhere('name', 'triage');

    expect($triage['guard'])->toBeNull()
        ->and($triage['reason'])->toBe("That step isn't available from where it is now.")
        ->and($triage['reason'])->not->toContain('triage')
        ->and($triage['reason'])->not->toContain('[');
});

it('applies the transition even when the Display emission blows up', function () {
    // The load-bearing Display-vs-Control invariant, asserted rather than asserted-about. The status
    // listener runs INSIDE `Workflow::apply()`, past the marking mutation, so an emit that raised
    // propagated out of apply() and turned an already-applied, perfectly legal transition into an
    // exception at the caller — i.e. a downed timeline broke execution correctness.
    app()->instance(StatusEmitter::class, new class(app('config'), app('events')) extends StatusEmitter
    {
        public function emit(?Model $subject, $event, ?string $runId = null, ?string $actor = null): ?Activity
        {
            throw new RuntimeException('the timeline is down');
        }
    });
    app()->forgetInstance(WorkflowRunner::class);
    app()->forgetInstance(LifecycleService::class);

    $ticket = SupportTicket::create(['status' => 'open']);

    $result = app(LifecycleService::class)->transition($ticket, 'triage');

    expect($result->applied)->toBeTrue()
        ->and($ticket->fresh()->status)->toBe('triaged');
});

it('returns an un-applied result for an unknown transition name rather than throwing', function () {
    // symfony's `can()` returns false for an undefined transition without raising; the
    // UndefinedTransitionException comes from `buildTransitionBlockerList()` in the rejection
    // branch. With the try wrapped around `can()` alone it escaped the runner, so a caller that
    // named a transition that does not exist got an exception instead of the documented "cannot"
    // result — and every branch-on-`applied` caller had to catch as well as check.
    $ticket = SupportTicket::create(['status' => 'open']);

    $result = app(LifecycleService::class)->transition($ticket, 'teleport');

    expect($result->applied)->toBeFalse()
        ->and($result->blockers[0])->toContain('Unknown transition')
        ->and($ticket->fresh()->status)->toBe('open');
});

it('is a no-op for an unmanaged model (unbound type)', function () {
    app(WorkflowBindingRegistry::class)->unbind('support-ticket');

    $ticket = SupportTicket::create(['status' => 'open']);
    $result = app(LifecycleService::class)->transition($ticket, 'triage');

    expect($result->applied)->toBeFalse()
        ->and($result->blockers[0])->toContain('not managed')
        ->and(app(LifecycleService::class)->manages($ticket))->toBeFalse()
        ->and(app(LifecycleService::class)->available($ticket))->toBe([]);
});
