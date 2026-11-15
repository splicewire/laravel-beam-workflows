<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class WorkflowReactionRevisionData extends BeamData
{
    public function __construct(
        #[Description('Reaction binding identifier returned when the follow-up was configured.')]
        public string $id,
        #[MapName('expected_revision')]
        #[Description('Revision read from the binding; the operation is refused if it has changed.')]
        public int $expectedRevision,
    ) {}
}
