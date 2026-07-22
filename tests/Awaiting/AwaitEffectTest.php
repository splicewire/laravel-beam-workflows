<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Awaiting\AwaitEffect;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\Contracts\WorkflowNotifier;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * The generic `workflow.await` effect (ticket 07/11): one attach that BOTH emails once (via the
 * host-bound WorkflowNotifier) AND stamps one durable inbox row per (entered place, principal) via the
 * host-bound AwaitingStore — forwarding opaque `kind:selector` principal strings + the opaque actor
 * token, never touching a User. Plus the SYNCHRONOUS clear-on-leave listener that sheds `from`-place
 * rows for every transition. Both are inert until the host binds the contracts.
 */

class AwaitTicket extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'await_tickets';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'await-ticket';
    }
}

/** An in-memory AwaitingStore that records every call — the host binds a real one (ticket 12). */
class SpyAwaitingStore implements AwaitingStore
{
    /** @var list<array{subject: mixed, place: string, principal: string}> */
    public array $stamped = [];

    /** @var list<array{subject: mixed, places: list<string>}> */
    public array $cleared = [];

    public function stamp(Model $subject, string $place, string $principal, ?string $parentType = null, ?string $parentId = null): void
    {
        $this->stamped[] = ['subject' => $subject->getKey(), 'place' => $place, 'principal' => $principal];
    }

    public function clearForPlaces(Model $subject, array $places): void
    {
        $this->cleared[] = ['subject' => $subject->getKey(), 'places' => array_values($places)];
    }

    public function clearForSubject(Model $subject): void {}
}

/** An in-memory WorkflowNotifier that records each notify call — the host binds a real one (ticket 13). */
class SpyWorkflowNotifier implements WorkflowNotifier
{
    /** @var list<array{principals: list<string>, actor: ?string, transition: string}> */
    public array $notified = [];

    public function notify(array $principals, Model $subject, WorkflowTransitioned $event, ?string $actor): void
    {
        $this->notified[] = ['principals' => array_values($principals), 'actor' => $actor, 'transition' => $event->transition];
    }
}

function awaitBlueprint(array $effects = [], array $effectParams = []): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'await.lifecycle',
        'places' => ['open', 'review', 'closed'],
        'initial' => ['open'],
        'transitions' => [
            [
                'name' => 'submit',
                'from' => 'open',
                'to' => 'review',
                'effects' => $effects,
                'metadata' => $effectParams ? ['effect_params' => $effectParams] : [],
            ],
            ['name' => 'close', 'from' => 'review', 'to' => 'closed'],
        ],
    ]);
}

beforeEach(function () {
    if (! Schema::hasTable('await_tickets')) {
        Schema::create('await_tickets', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('open');
            $table->uuid('workflow_version')->nullable();
        });
    }

    $this->store = new SpyAwaitingStore;
    $this->notifier = new SpyWorkflowNotifier;
    app()->instance(AwaitingStore::class, $this->store);
    app()->instance(WorkflowNotifier::class, $this->notifier);
});

it('registers `workflow.await` in the effect catalog with a principals param', function () {
    $catalog = collect(app(TransitionEffectRegistry::class)->effectCatalog())
        ->keyBy('name');

    expect($catalog)->toHaveKey(AwaitEffect::REF)
        ->and($catalog[AwaitEffect::REF]['paramsSchema']['properties'])->toHaveKey('principals');
});

it('notifies once and stamps one inbox row per principal on the entered place', function () {
    app(DefinitionStore::class)->ensureSystemLineage('await.lifecycle', 'Await Lifecycle',
        awaitBlueprint(effects: [AwaitEffect::REF], effectParams: [AwaitEffect::REF => ['principals' => ['owner:', 'role:editor']]]));
    app(WorkflowBindingRegistry::class)->bind('await-ticket', 'await.lifecycle');

    app(LifecycleService::class)->transition(
        AwaitTicket::create(['status' => 'open']),
        'submit',
        context: new TransitionContext(actor: 'user:9'),
    );

    // Email: exactly once, all principals together, carrying the opaque actor for host self-exclusion.
    expect($this->notifier->notified)->toHaveCount(1)
        ->and($this->notifier->notified[0]['principals'])->toBe(['owner:', 'role:editor'])
        ->and($this->notifier->notified[0]['actor'])->toBe('user:9');

    // Inbox: one row per principal, on the entered place `review`.
    expect($this->store->stamped)->toHaveCount(2)
        ->and(collect($this->store->stamped)->pluck('place')->unique()->all())->toBe(['review'])
        ->and(collect($this->store->stamped)->pluck('principal')->all())->toBe(['owner:', 'role:editor']);
});

it('clears the from-place awaitings on every transition — before the stamp (synchronous)', function () {
    app(DefinitionStore::class)->ensureSystemLineage('await.lifecycle', 'Await Lifecycle',
        awaitBlueprint(effects: [AwaitEffect::REF], effectParams: [AwaitEffect::REF => ['principals' => ['owner:']]]));
    app(WorkflowBindingRegistry::class)->bind('await-ticket', 'await.lifecycle');

    $ticket = AwaitTicket::create(['status' => 'open']);
    app(LifecycleService::class)->transition($ticket, 'submit'); // open → review

    // The clear-on-leave fired for the `open` place the marking left.
    expect($this->store->cleared)->toHaveCount(1)
        ->and($this->store->cleared[0]['places'])->toBe(['open']);

    // A subsequent effect-LESS transition still sheds its from-place rows.
    app(LifecycleService::class)->transition($ticket->refresh(), 'close'); // review → closed
    expect($this->store->cleared)->toHaveCount(2)
        ->and($this->store->cleared[1]['places'])->toBe(['review']);
});

it('is a no-op when no principals are configured', function () {
    app(DefinitionStore::class)->ensureSystemLineage('await.lifecycle', 'Await Lifecycle',
        awaitBlueprint(effects: [AwaitEffect::REF], effectParams: [AwaitEffect::REF => ['principals' => []]]));
    app(WorkflowBindingRegistry::class)->bind('await-ticket', 'await.lifecycle');

    app(LifecycleService::class)->transition(AwaitTicket::create(['status' => 'open']), 'submit');

    expect($this->notifier->notified)->toBeEmpty()
        ->and($this->store->stamped)->toBeEmpty();
});
