<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

class WorkflowReactionRetryData extends BeamData
{
    public function __construct(
        #[Description('Delivery identifier from the reaction record to retry.')]
        public string $id,
        #[MapName('expected_attempts')]
        #[Description('Attempt count read from that delivery; retry is refused if it has changed.')]
        public int $expectedAttempts,
    ) {}
}
