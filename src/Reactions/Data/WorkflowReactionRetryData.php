<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowReactionRetryData extends BeamData
{
    public function __construct(public string $id, #[MapName('expected_attempts')] public int $expectedAttempts) {}
}
