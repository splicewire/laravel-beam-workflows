<?php

namespace Splicewire\Beam\Workflows\Actions\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowActionData extends BeamData
{
    public function __construct(
        #[MapName('subject_kind')]
        public string $subjectKind,
        #[MapName('subject_id')]
        public string $subjectId,
        public string $transition,
        #[MapName('definition_version')]
        public ?string $definitionVersion = null,
    ) {}
}
