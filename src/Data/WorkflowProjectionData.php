<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The model-blind definition projection the runtime `<WorkflowStepper>` renders (beam-workflows v2
 * ticket 07): the type key, the pinned version's places + transitions, the current marking, and the
 * backend-computed available transitions. The UI drives its buttons off `available`, never a
 * hardcoded list. A move out of the current place that is not available carries its refusal in its
 * transition's `reason` ({@see WorkflowTransitionData}), so the UI can say why rather than hide it.
 */
#[TypeScript]
class WorkflowProjectionData extends BeamData
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
