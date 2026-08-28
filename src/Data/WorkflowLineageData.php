<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A workflow definition lineage (beam-workflows v2 ticket 03): a stable identity owning ordered,
 * immutable versions, plus the types currently bound to it (ticket 09). `isSystem` marks a seeded
 * default a tenant may adopt or fork.
 */
#[TypeScript]
class WorkflowLineageData extends BeamData
{
    public function __construct(
        public string $key,
        public string $name,
        public bool $isSystem,
        /** @var string[] */
        public array $boundTypes,
        /** @var WorkflowVersionData[] */
        public array $versions,
    ) {}
}
