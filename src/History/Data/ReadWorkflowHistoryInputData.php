<?php

namespace Splicewire\Beam\Workflows\History\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class ReadWorkflowHistoryInputData extends BeamData
{
    public function __construct(
        #[MapName('subject_kind')] #[Description('Registered workflow subject kind identifying the resource to authorize.')]
        public string $subjectKind,
        #[MapName('subject_id')] #[Description('Record identifier of the workflow subject.')]
        public string $subjectId,
        #[Description('Maximum number of transition facts to return in this page.')]
        public int $limit = 50,
        #[Description('Transition fact identifier returned as next_before by the preceding page; returns older facts for the same subject.')]
        public ?string $before = null,
    ) {}
}
