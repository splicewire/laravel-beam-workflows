<?php

namespace Splicewire\Beam\Workflows\Circuit\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/** Authored node configuration; execution authority and the definition pin are host-owned. */
class WorkflowSubjectInputData extends BeamData
{
    public function __construct(
        #[MapName('subject_kind')]
        public string $subjectKind,
        #[MapName('subject_id')]
        public string $subjectId,
        public string $transition,
    ) {}
}
