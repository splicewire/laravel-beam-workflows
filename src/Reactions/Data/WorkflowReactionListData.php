<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class WorkflowReactionListData extends BeamData
{
    /** @param list<WorkflowReactionRecordData> $reactions */
    public function __construct(public array $reactions) {}
}
