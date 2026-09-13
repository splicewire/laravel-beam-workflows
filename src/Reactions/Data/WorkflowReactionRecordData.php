<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Splicewire\Beam\Data\BeamData;

class WorkflowReactionRecordData extends BeamData
{
    /** @param list<WorkflowReactionDeliveryData> $deliveries */
    public function __construct(public string $id, public int $revision, public bool $enabled,
        public WorkflowReactionData $configuration, public array $deliveries) {}
}
