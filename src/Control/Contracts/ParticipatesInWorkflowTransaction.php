<?php

namespace Splicewire\Beam\Workflows\Control\Contracts;

use Splicewire\Beam\Workflows\Control\TransitionContext;

/** Optional same-connection writes after admission, before workflow marking/fact persistence. */
interface ParticipatesInWorkflowTransaction
{
    /** Throw to roll back the entire transition. External effects belong after commit. */
    public function participateInWorkflowTransaction(string $transition, TransitionContext $context): void;
}
