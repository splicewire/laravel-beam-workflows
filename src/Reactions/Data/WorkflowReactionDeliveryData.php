<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowReactionDeliveryData extends BeamData
{
    /** @param list<string> $blockers */
    public function __construct(public string $id, public string $status,
        #[MapName('transition_id')] public string $transitionId,
        #[MapName('action_id')] public ?string $actionId,
        #[MapName('anchored_at')] public string $anchoredAt,
        public int $attempts, public array $blockers) {}
}
