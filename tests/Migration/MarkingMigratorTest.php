<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Migration\MarkingMigrator;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * Ticket 06 — the explicit, guarded marking migration. A clean remap re-pins the cohort; an
 * un-mappable marking aborts the WHOLE run (nothing written) and reports the offenders; a dry run
 * writes nothing.
 */

class MigratingItem extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'migrating_items';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'migrating-item';
    }
}

function v1Blueprint(): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'item.lifecycle',
        'places' => ['draft', 'review', 'done'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'finish', 'from' => 'draft', 'to' => 'done']],
    ]);
}

/** v2 renames `review` → `in_review` and drops `draft`'s old name to `open`. */
function v2Blueprint(): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'item.lifecycle',
        'places' => ['open', 'in_review', 'done'],
        'initial' => ['open'],
        'transitions' => [['name' => 'finish', 'from' => 'open', 'to' => 'done']],
    ]);
}

beforeEach(function () {
    if (! Schema::hasTable('migrating_items')) {
        Schema::create('migrating_items', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status');
            $table->uuid('workflow_version')->nullable();
        });
    }

    $store = app(DefinitionStore::class);
    $store->createLineage('item.lifecycle', 'Item Lifecycle', v1Blueprint());
    $this->v1 = $store->activeVersion('item.lifecycle')->id;
    $store->fork('item.lifecycle', v2Blueprint());
    $this->v2 = $store->activeVersion('item.lifecycle')->id;
});

it('re-pins a cleanly-mappable cohort and emits a Display event per object', function () {
    $a = MigratingItem::create(['status' => 'draft', 'workflow_version' => $this->v1]);
    $b = MigratingItem::create(['status' => 'review', 'workflow_version' => $this->v1]);

    $report = app(MarkingMigrator::class)->migrate(
        'item.lifecycle', $this->v1, $this->v2,
        placeMap: ['draft' => 'open', 'review' => 'in_review', 'done' => 'done'],
        cohort: MigratingItem::where('workflow_version', $this->v1)->get(),
        runId: 'mig-1',
    );

    expect($report->applied)->toBeTrue()
        ->and($report->migrated)->toBe(2)
        ->and($a->fresh()->status)->toBe('open')
        ->and($a->fresh()->workflow_version)->toBe($this->v2)
        ->and($b->fresh()->status)->toBe('in_review')
        ->and($b->fresh()->workflow_version)->toBe($this->v2);

    // A Display event per migrated object, grouped under the run id.
    expect(Activity::query()->where('log_name', 'status')->where('properties->run_id', 'mig-1')->count())->toBe(2);
});

it('aborts the whole run and reports offenders when a marking is un-mappable', function () {
    $ok = MigratingItem::create(['status' => 'draft', 'workflow_version' => $this->v1]);
    $bad = MigratingItem::create(['status' => 'review', 'workflow_version' => $this->v1]);

    // The map omits `review` → nothing maps it.
    $report = app(MarkingMigrator::class)->migrate(
        'item.lifecycle', $this->v1, $this->v2,
        placeMap: ['draft' => 'open', 'done' => 'done'],
        cohort: MigratingItem::where('workflow_version', $this->v1)->get(),
    );

    expect($report->applied)->toBeFalse()
        ->and($report->ok())->toBeFalse()
        ->and($report->unmappable)->toBe([['id' => (string) $bad->getKey(), 'place' => 'review']])
        // NOTHING migrated — not even the mappable one. All-or-nothing.
        ->and($ok->fresh()->status)->toBe('draft')
        ->and($ok->fresh()->workflow_version)->toBe($this->v1)
        ->and($bad->fresh()->status)->toBe('review');
});

it('a dry run reports what would migrate but writes nothing', function () {
    $a = MigratingItem::create(['status' => 'draft', 'workflow_version' => $this->v1]);

    $report = app(MarkingMigrator::class)->migrate(
        'item.lifecycle', $this->v1, $this->v2,
        placeMap: ['draft' => 'open', 'review' => 'in_review', 'done' => 'done'],
        cohort: MigratingItem::where('workflow_version', $this->v1)->get(),
        dryRun: true,
    );

    expect($report->dryRun)->toBeTrue()
        ->and($report->applied)->toBeFalse()
        ->and($report->ok())->toBeTrue()
        ->and($report->migrated)->toBe(1)
        // Nothing written.
        ->and($a->fresh()->status)->toBe('draft')
        ->and($a->fresh()->workflow_version)->toBe($this->v1)
        ->and(Activity::query()->where('log_name', 'status')->count())->toBe(0);
});

it('rejects a place map that targets a place the new version does not declare', function () {
    expect(fn () => app(MarkingMigrator::class)->migrate(
        'item.lifecycle', $this->v1, $this->v2,
        placeMap: ['draft' => 'nonexistent'],
        cohort: [],
    ))->toThrow(InvalidArgumentException::class, 'does not declare');
});
