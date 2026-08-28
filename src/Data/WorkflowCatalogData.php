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
        /**
         * Backed by `splicewire/tower`'s `GuardCatalogEntryData` — fully-qualified (not `use`-
         * imported) since beam must never depend on tower; the TypeScript transformer's docblock
         * resolver only recognizes a bare class name via `class_exists()`/a same-namespace guess,
         * so leaving this unqualified silently degrades the emitted type to `unknown[]`.
         *
         * @var \Splicewire\Tower\Data\GuardCatalogEntryData[]
         */
        public array $guards,
        /**
         * The post-transition effect catalog (same shape as guards) — see the `$guards` docblock
         * for why this must stay fully-qualified.
         *
         * @var \Splicewire\Tower\Data\GuardCatalogEntryData[]
         */
        public array $effects,
        /** @var WorkflowTypeOptionData[] */
        public array $types,
        /**
         * The recipient-picker vocabulary (ticket 17): the kinds an effect's `principals` param can
         * target (`owner`/`watcher` flat, `role` with options). Host-populated, declarative. See the
         * `$guards` docblock for why this must stay fully-qualified.
         *
         * @var \Splicewire\Tower\Data\PrincipalKindData[]
         */
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
