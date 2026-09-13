<?php

namespace Splicewire\Beam\Workflows\Circuit;

use LogicException;
use Splicewire\Beam\Workflows\Circuit\Contracts\WorkflowCircuitExecutionProvider;

/** Bind scoped in the host and enter only around an actual persisted node visit. */
class WorkflowCircuitExecutionScope implements WorkflowCircuitExecutionProvider
{
    private ?WorkflowCircuitExecution $execution = null;

    public function current(): WorkflowCircuitExecution
    {
        return $this->execution ?? throw new LogicException('A trusted Circuit node visit is required.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(WorkflowCircuitExecution $execution, callable $callback): mixed
    {
        $previous = $this->execution;
        $this->execution = $execution;
        try {
            return $callback();
        } finally {
            $this->execution = $previous;
        }
    }
}
