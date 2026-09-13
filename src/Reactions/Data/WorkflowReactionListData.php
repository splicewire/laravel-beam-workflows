<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Splicewire\Beam\Data\BeamData;

class WorkflowReactionListData extends BeamData
{
    /** @param list<WorkflowReactionRecordData> $reactions */
    public function __construct(public array $reactions) {}
}
