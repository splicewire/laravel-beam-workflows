<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A type→workflow binding (beam-workflows v2 tickets 02/09): the `typeKey` this governs, the
 * `lineageRef` it points at, and the guard `params` bag. Its existence IS the enable; its absence
 * is the generic unmanaged fallback.
 */
#[TypeScript]
class WorkflowBindingData extends BeamData
{
    public function __construct(
        public string $typeKey,
        public string $lineageRef,
        /** @var array<string, mixed> */
        public array $params,
    ) {}
}
