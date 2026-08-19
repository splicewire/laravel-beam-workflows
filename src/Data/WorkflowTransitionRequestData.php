<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * The `input:` shape a generic `#[ParticleOp(kind: OperationKind::Write)]` transition action accepts —
 * just the transition NAME the stepper's clicked button carries. Model-blind: any host wiring
 * `WorkflowActuator::transition()` over HTTP for any {@see \Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged}
 * model reuses this same shape, same reason {@see WorkflowProjectionData} isn't beam-ux-specific either.
 */
#[TypeScript]
class WorkflowTransitionRequestData extends Data
{
    public function __construct(
        public string $transition,
    ) {}
}
