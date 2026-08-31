<?php

use Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;
use Splicewire\Beam\Workflows\Data\WorkflowAwaitingRowData;

/*
 * `WorkflowAwaitingRowData` (operator-surface-prototypes ticket, Direction C step 3 — the "Workflow
 * Queue" dashboard) is the zero-glue `#[ParticleResource]` tier `GitRepoData` demonstrates: no
 * `project()` override, so `ParticleController::projectRecord()` falls through to
 * `WorkflowAwaitingRowData::from($model)` — spatie/laravel-data's own model→Data mapping by property
 * name. This proves that mapping actually round-trips for a real persisted row, including the
 * Carbon-cast `created_at` column coercing into the Data class's plain `?string` property (the same
 * shape `GitRepo::checked_at`/`GitRepoData::$checked_at` already rely on).
 */

it('maps a persisted WorkflowAwaiting row onto the Data class with no project() override', function () {
    $awaiting = WorkflowAwaiting::create([
        'subject_type' => 'composition',
        'subject_id' => (string) Illuminate\Support\Str::uuid(),
        'place' => 'review',
        'principal' => 'role:reviewer',
    ]);

    $row = WorkflowAwaitingRowData::from($awaiting);

    expect($row->id)->toBe($awaiting->id)
        ->and($row->subject_type)->toBe('composition')
        ->and($row->subject_id)->toBe($awaiting->subject_id)
        ->and($row->place)->toBe('review')
        ->and($row->principal)->toBe('role:reviewer')
        ->and($row->parent_type)->toBeNull()
        ->and($row->parent_id)->toBeNull()
        ->and($row->created_at)->toBeString();
});

it('is registered as a read-only #[ParticleResource] keyed workflow-awaiting', function () {
    $resource = Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery::resourceFromAttribute(WorkflowAwaitingRowData::class);

    expect($resource->key)->toBe('workflow-awaiting')
        ->and($resource->backing)->toBe(WorkflowAwaiting::class)
        ->and($resource->readOnly)->toBeTrue()
        ->and($resource->project)->toBeNull();
});
