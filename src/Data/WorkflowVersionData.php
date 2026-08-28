<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One immutable version of a workflow definition lineage (beam-workflows v2 ticket 03): its ordered
 * `version` number, the `isActive` pointer, and the frozen blueprint snapshot. Editing forks a new
 * version — a version row is never rewritten.
 */
#[TypeScript]
class WorkflowVersionData extends BeamData
{
    public function __construct(
        public string $id,
        public int $version,
        public bool $isActive,
        public WorkflowBlueprintData $blueprint,
    ) {}
}
