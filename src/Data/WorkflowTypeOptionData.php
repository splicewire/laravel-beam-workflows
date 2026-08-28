<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One governable workflow-type option (beam-workflows v2): a stable `key` and a human `label`. The
 * binding-config admin renders these as the "govern this type" dropdown, so an operator picks a
 * known type instead of typing a free-text key.
 */
#[TypeScript]
class WorkflowTypeOptionData extends BeamData
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
