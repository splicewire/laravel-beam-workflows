<?php

namespace Splicewire\Beam\Workflows\Localization;

use Splicewire\Beam\Data\BeamData;

class WorkflowErrorData extends BeamData
{
    public function __construct(public string $message, public string $code) {}
}
