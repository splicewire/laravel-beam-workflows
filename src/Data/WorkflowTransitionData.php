<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One transition of a workflow definition, as data (beam-workflows v2). `from`/`to` are always
 * lists of place names (workflow-net safe); `guard` is a reference resolved through the guard
 * catalog — never inline logic.
 *
 * In a record's projection ({@see \Splicewire\Beam\Workflows\Control\LifecycleService::projection()}),
 * `reason` says why this move is refused right now: the guard's own sentence, set only on a transition
 * that leaves the record's current place and is not available. Null for an available move, for a
 * transition elsewhere in the graph, and in a definition read with no record behind it.
 */
#[TypeScript]
class WorkflowTransitionData extends BeamData
{
    public function __construct(
        public string $name,
        /** @var string[] */
        public array $from,
        /** @var string[] */
        public array $to,
        public ?string $guard = null,
        /** @var string[] Post-transition effect refs (the notifier catalog). */
        public array $effects = [],
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
        public ?string $reason = null,
    ) {}
}
