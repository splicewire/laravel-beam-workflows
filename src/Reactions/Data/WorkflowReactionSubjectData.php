<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowReactionSubjectData extends BeamData
{
    public function __construct(#[MapName('subject_kind')] public string $subjectKind,
        #[MapName('subject_id')] public string $subjectId) {}
}
