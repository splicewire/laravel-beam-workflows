<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowReactionRevisionData extends BeamData
{
    public function __construct(public string $id, #[MapName('expected_revision')] public int $expectedRevision) {}
}
