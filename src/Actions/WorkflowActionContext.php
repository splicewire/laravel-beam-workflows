<?php

namespace Splicewire\Beam\Workflows\Actions;

/** Host-derived provenance; the authority resolves these tokens on every use. */
final readonly class WorkflowActionContext
{
    public function __construct(
        public string $principal,
        public string $creator,
        public string $tenantToken,
        public ?string $runId = null,
        public ?string $causationId = null,
        public array $causalPath = [],
    ) {}
}
