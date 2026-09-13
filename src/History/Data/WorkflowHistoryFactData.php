<?php

namespace Splicewire\Beam\Workflows\History\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Workflows\Control\WorkflowTransitionFact;

class WorkflowHistoryFactData extends BeamData
{
    /**
     * @param array<string, int> $from
     * @param array<string, int> $to
     * @param list<string> $causalPath
     */
    public function __construct(
        #[MapName('transition_id')] public string $transitionId,
        public string $transition,
        public array $from,
        public array $to,
        public ?string $actor,
        #[MapName('run_id')] public ?string $runId,
        #[MapName('causation_id')] public ?string $causationId,
        #[MapName('causal_path')] public array $causalPath,
        #[MapName('occurred_at')] public string $occurredAt,
        #[MapName('definition_version')] public ?string $definitionVersion,
    ) {}

    public static function fromFact(WorkflowTransitionFact $fact): self
    {
        return new self((string) $fact->id, $fact->transition, $fact->from, $fact->to, $fact->actor,
            $fact->run_id, $fact->causation_id, $fact->causal_path, $fact->occurred_at->utc()->toISOString(), $fact->version_id);
    }
}
