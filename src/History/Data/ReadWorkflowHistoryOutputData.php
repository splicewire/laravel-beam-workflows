<?php

namespace Splicewire\Beam\Workflows\History\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class ReadWorkflowHistoryOutputData extends BeamData
{
    /** @param list<WorkflowHistoryFactData> $facts */
    public function __construct(public array $facts, #[MapName('next_before')] public ?string $nextBefore = null) {}
}
