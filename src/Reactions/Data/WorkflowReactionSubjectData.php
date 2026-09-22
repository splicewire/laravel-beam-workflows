<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowReactionSubjectData extends BeamData
{
    public function __construct(
        #[MapName('subject_kind')]
        #[Description('Registered workflow subject kind whose follow-up bindings are requested.')]
        public string $subjectKind,
        #[MapName('subject_id')]
        #[Description('Record identifier of the workflow subject.')]
        public string $subjectId,
    ) {}
}
