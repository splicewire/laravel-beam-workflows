<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * Coverage for one workflow lineage (beam-workflows v2 — visibility): the total count of live
 * records pinned to any of its versions, plus the per-version breakdown. This is what turns a
 * binding from "configured" into "demonstrably governing N records on version X".
 */
#[TypeScript]
class WorkflowCoverageData extends Data
{
    public function __construct(
        public string $lineageKey,
        public int $total,
        /** @var WorkflowCoverageVersionData[] */
        public array $versions,
    ) {}
}
