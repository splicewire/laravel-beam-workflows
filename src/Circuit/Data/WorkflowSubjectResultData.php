<?php

namespace Splicewire\Beam\Workflows\Circuit\Data;

use LogicException;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Control\TransitionResult;

class WorkflowSubjectResultData extends BeamData
{
    /**
     * @param  list<string>  $marking
     * @param  list<string>  $blockers
     */
    public function __construct(
        #[MapName('subject_kind')]
        public string $subjectKind,
        #[MapName('subject_id')]
        public string $subjectId,
        public array $marking,
        public string $transition,
        public bool $applied,
        public array $blockers,
        #[MapName('transition_id')]
        public string $transitionId,
    ) {}

    public static function fromResult(WorkflowActionData $request, TransitionResult $result): self
    {
        return new self($request->subjectKind, $request->subjectId, $result->marking,
            $result->transition, $result->applied, $result->blockers,
            $result->transitionId ?? throw new LogicException('An applied subject action must identify its committed transition.'));
    }
}
