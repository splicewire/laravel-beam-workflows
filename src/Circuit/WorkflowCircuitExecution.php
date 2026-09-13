<?php

namespace Splicewire\Beam\Workflows\Circuit;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;

/** A host-verified, durably identified node visit and its previously prepared request. */
final readonly class WorkflowCircuitExecution
{
    public function __construct(
        public string $identity,
        public WorkflowActionData $request,
        public WorkflowActionContext $context,
        public ConnectionInterface $connection,
    ) {
        if ($identity === '' || $request->definitionVersion === null || $context->runId === null) {
            throw new InvalidArgumentException('Circuit execution requires a durable visit identity, prepared definition pin and run identity.');
        }
    }
}
