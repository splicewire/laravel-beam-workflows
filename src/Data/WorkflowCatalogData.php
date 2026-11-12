<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The editor's contract (beam-workflows v2 ticket 08): the WorkflowBlueprint JSON Schema the form
 * binds to, plus the guard catalog the guard picker renders.
 */
#[TypeScript]
class WorkflowCatalogData extends BeamData
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $blueprintSchema,
        /** @var \Splicewire\Beam\Workflows\Data\GuardCatalogEntryData[] */
        public array $guards,
        /** @var \Splicewire\Beam\Workflows\Data\GuardCatalogEntryData[] */
        public array $effects,
        /** @var WorkflowTypeOptionData[] */
        public array $types,
        /** @var \Splicewire\Beam\Workflows\Data\PrincipalKindData[] */
        public array $principals = [],
        /**
         * Whether the current user holds the `author-workflows` capability (admin-redesign ticket 08).
         * Server-authoritative so the surface can disable its own write affordances (Save / Bind /
         * Migrate) for a read-only member rather than let them submit into a 403. The gate is still
         * enforced server-side on every write path — this flag is presentation only.
         */
        public bool $canAuthor = false,
    ) {}
}
