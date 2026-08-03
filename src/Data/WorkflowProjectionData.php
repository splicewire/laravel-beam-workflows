<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * The model-blind definition projection the runtime `<WorkflowStepper>` renders (beam-workflows v2
 * ticket 07): the type key, the pinned version's places + transitions, the current marking, and the
 * backend-computed available transitions. The UI drives its buttons off `available`, never a
 * hardcoded list.
 */
#[TypeScript]
class WorkflowProjectionData extends Data
{
    public function __construct(
        public string $type,
        /** @var string[] */
        public array $places,
        /** @var WorkflowTransitionData[] */
        public array $transitions,
        public string $current,
        /** @var string[] */
        public array $available,
    ) {}
}
