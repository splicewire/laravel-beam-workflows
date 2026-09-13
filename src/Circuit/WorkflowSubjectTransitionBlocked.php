<?php

namespace Splicewire\Beam\Workflows\Circuit;

use RuntimeException;
use Splicewire\Beam\Workflows\Control\TransitionResult;

/** The engine records this node as failed and skips its success-dependent descendants. */
class WorkflowSubjectTransitionBlocked extends RuntimeException
{
    public function __construct(public readonly TransitionResult $result)
    {
        parent::__construct('Workflow transition blocked: '.implode('; ', $result->blockers));
    }
}
