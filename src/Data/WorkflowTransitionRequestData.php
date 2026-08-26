<?php

namespace Splicewire\Beam\Workflows\Data;

use Schemastud\DataSchemas\Attributes\Description;
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
        // Described on the attribute rather than only in the class docblock above: `JsonSchemaGenerator`
        // reads attributes, not prose, so a property explained only in a docblock reaches the reference
        // and the generated SDK blank. api-surface-coherence ticket 96's guard is what measured that.
        #[Description(
            'Name of the transition to take, as declared on the model\'s workflow — not the name of the '.
            'destination state. Which transitions are legal depends on the record\'s CURRENT state, so '.
            'read the available set off the projection rather than hardcoding it.'
        )]
        public string $transition,
    ) {}
}
