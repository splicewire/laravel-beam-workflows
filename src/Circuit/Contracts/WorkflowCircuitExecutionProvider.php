<?php

namespace Splicewire\Beam\Workflows\Circuit\Contracts;

use Splicewire\Beam\Workflows\Circuit\WorkflowCircuitExecution;

/** Supplied by the host's actual node execution boundary, never resolved from invocation input. */
interface WorkflowCircuitExecutionProvider
{
    public function current(): WorkflowCircuitExecution;
}
