<?php

namespace Splicewire\Beam\Workflows\History\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class ReadWorkflowHistoryInputData extends BeamData
{
    public function __construct(
        #[MapName('subject_kind')] public string $subjectKind,
        #[MapName('subject_id')] public string $subjectId,
        public int $limit = 50,
        public ?string $before = null,
    ) {}
}
