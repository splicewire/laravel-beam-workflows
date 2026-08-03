<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * One transition of a workflow definition, as data (beam-workflows v2). `from`/`to` are always
 * lists of place names (workflow-net safe); `guard` is a reference resolved through the guard
 * catalog — never inline logic.
 */
#[TypeScript]
class WorkflowTransitionData extends Data
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
    ) {}
}
