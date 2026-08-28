<?php

namespace Splicewire\Beam\Workflows\Data;

use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Operators\Exact;
use Schemastud\Frame\Attributes\Column;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;

/**
 * The Frame resource declaration for the "Workflow Queue" tool (operator-surface-prototypes ticket 03,
 * Direction C — "everything awaiting a transition/review") — one row per {@see WorkflowAwaiting}. The
 * SAME zero-glue `ParticleResource` tier {@see \Splicewire\Beam\Ux\Data\BeamUxEntryData}/
 * `\Splicewire\Beam\Data\GitRepoData`/`\Splicewire\Beam\Ux\Data\MirrorStatusRowData` (laravel-beam-ux)
 * ride. `readOnly: true` — nothing writes an awaiting row through Frame; it's stamped/cleared
 * exclusively by {@see \Splicewire\Beam\Workflows\Awaiting\EloquentAwaitingStore} off the
 * `workflow.await` effect and `ClearAwaitingsOnTransition`.
 *
 * No `project()` — every field is a plain, already-persisted column (mirrors `GitRepoData`'s own
 * zero-glue shape, not `MirrorStatusRowData`'s computed one): the annotated properties map 1:1 onto
 * `WorkflowAwaiting`'s columns by name, so the framework's default model→Data resolution does the work.
 *
 * `subject_type`/`subject_id` stay raw (a morph alias/FQCN + a UUID) rather than resolved to a friendly
 * label — `WorkflowAwaiting` is deliberately identity-blind and polymorphic across EVERY governed type
 * in the app (not just `BeamUxEntry`), so any resolution here would need a per-type lookup this generic
 * row has no business owning. `place` is already human-legible (blueprint place names ARE the display
 * vocabulary, per {@see WorkflowProjectionData}'s `places: string[]`); `principal` is the opaque
 * `kind:selector` recipient token by design (never a resolved user).
 */
#[ParticleResource(
    key: 'workflow-awaiting',
    backing: WorkflowAwaiting::class,
    label: 'Workflow Queue',
    group: 'Ops',
    icon: 'inbox',
    section: 'ops',
    readOnly: true,
)]
#[TypeScript]
class WorkflowAwaitingRowData extends BeamData
{
    public function __construct(
        public string $id,
        #[Column(label: 'Subject Type', sort: 0), Filterable(Exact::class)]
        public string $subject_type,
        #[Column(label: 'Subject', sort: 1)]
        public string $subject_id,
        #[Column(label: 'Place', sort: 2), Filterable(Exact::class), Sortable]
        public string $place,
        #[Column(label: 'Principal', sort: 3), Filterable(Exact::class), Sortable]
        public string $principal,
        public ?string $parent_type,
        public ?string $parent_id,
        #[Column(label: 'Waiting Since', sort: 4), Sortable(default: true)]
        public ?string $created_at,
    ) {}
}
