<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * How many live records are pinned to one workflow definition version (beam-workflows v2 — the
 * "is it active" evidence). A non-zero count means real objects are running under this exact,
 * immutable version of the graph.
 */
#[TypeScript]
class WorkflowCoverageVersionData extends Data
{
    public function __construct(
        public string $id,
        public int $version,
        public bool $isActive,
        public int $count,
    ) {}
}
