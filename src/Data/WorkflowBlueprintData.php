<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * A workflow definition described as data (beam-workflows v2) — the shape the `<WorkflowEditor>`
 * binds to and the shape a stored version snapshots. Places + transitions + the initial marking;
 * guards ride the transitions as catalog references.
 */
#[TypeScript]
class WorkflowBlueprintData extends Data
{
    public function __construct(
        public string $name,
        /** @var string[] */
        public array $places,
        /** @var string[] */
        public array $initial,
        /** @var WorkflowTransitionData[] */
        public array $transitions,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
    ) {}
}
