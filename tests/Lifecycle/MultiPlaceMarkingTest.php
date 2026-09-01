<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * The single-place-persistence constraint.
 *
 * The engine is multi-token by construction — `WorkflowFactory` builds with `singleState: false`,
 * `MarkingSubject::places()` is a list, `TransitionBlueprint::$to` is a list — but a LIFECYCLE
 * projects the marking onto a scalar status attribute. `LifecycleService::project()` used to write
 * `$marking[0]` and return, so every place after the first was discarded with no exception, no log,
 * and `applied: true`. These tests pin the replacement: a marking the seam cannot store is a
 * BLOCKER, and nothing is written.
 */

/** A parcel that FORKS: `split` produces two places at once — a genuine workflow-net move. */
class ForkingParcel extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'forking_parcels';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'forking-parcel';
    }
}

function forkBlueprint(): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'parcel.fork',
        'places' => ['received', 'weighing', 'labelling', 'shipped'],
        'initial' => ['received'],
        'transitions' => [
            // The defect's subject: ONE transition, TWO produced places.
            ['name' => 'split', 'from' => 'received', 'to' => ['weighing', 'labelling']],
            // A single-place control on the same blueprint.
            ['name' => 'ship', 'from' => 'weighing', 'to' => 'shipped'],
        ],
    ]);
}

beforeEach(function () {
    if (! Schema::hasTable('forking_parcels')) {
        Schema::create('forking_parcels', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('received');
            $table->uuid('workflow_version')->nullable();
        });
    }

    app(DefinitionStore::class)->ensureSystemLineage('parcel.fork', 'Parcel Fork', forkBlueprint());
    app(WorkflowBindingRegistry::class)->bind('forking-parcel', 'parcel.fork');
});

it('refuses a transition whose marking a scalar status column cannot hold, instead of truncating it', function () {
    $parcel = ForkingParcel::create(['status' => 'received']);

    $result = app(LifecycleService::class)->transition($parcel, 'split');

    // BEFORE the fix this read `applied: true` with `status = 'weighing'` and `labelling` gone.
    expect($result->applied)->toBeFalse()
        ->and($result->blockers)->not->toBeEmpty()
        ->and($result->blockers[0])->toContain('multi-token')
        ->and($result->blockers[0])->toContain('weighing')
        ->and($result->blockers[0])->toContain('labelling');

    // Nothing was written: the refusal is total, not a partial apply.
    expect($parcel->fresh()->status)->toBe('received')
        ->and($parcel->fresh()->workflow_version)->toBeNull();
});

it('still applies and persists a single-place transition on the same blueprint', function () {
    $parcel = ForkingParcel::create(['status' => 'weighing']);

    $result = app(LifecycleService::class)->transition($parcel, 'ship');

    expect($result->applied)->toBeTrue()
        ->and($result->marking)->toBe(['shipped'])
        ->and($parcel->fresh()->status)->toBe('shipped');
});

it('refuses when the blueprint STARTS multi-place, rather than silently starting at its first place', function () {
    // A model with no status yet falls back to the blueprint's `initial`, which is declared as a
    // list. Reading only its first element was the read-side twin of the write-side truncation.
    app(DefinitionStore::class)->ensureSystemLineage(
        'parcel.multi-start',
        'Parcel Multi Start',
        WorkflowBlueprint::fromArray([
            'name' => 'parcel.multi-start',
            'places' => ['weighing', 'labelling', 'shipped'],
            'initial' => ['weighing', 'labelling'],
            'transitions' => [
                ['name' => 'ship', 'from' => ['weighing', 'labelling'], 'to' => 'shipped'],
            ],
        ]),
    );
    app(WorkflowBindingRegistry::class)->bind('forking-parcel', 'parcel.multi-start');

    $parcel = ForkingParcel::create(['status' => '']);

    $result = app(LifecycleService::class)->transition($parcel, 'ship');

    expect($result->applied)->toBeFalse()
        ->and($result->blockers[0])->toContain('multi-token')
        ->and($parcel->fresh()->status)->toBe('');
});

it('raises rather than truncating if a multi-place marking reaches project() directly', function () {
    // project() is the slot that had the defect. Callers filter before reaching it, so arriving here
    // with two places is a broken caller — an invariant a subclass author controls — and it throws.
    $service = app(LifecycleService::class);

    $project = (new ReflectionClass($service))->getMethod('project');
    $project->setAccessible(true);

    expect(fn () => $project->invoke($service, ForkingParcel::create(['status' => 'received']), ['weighing', 'labelling'], null))
        ->toThrow(LogicException::class, '2-place marking');
});
