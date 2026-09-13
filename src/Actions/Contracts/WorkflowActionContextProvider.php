<?php

namespace Splicewire\Beam\Workflows\Actions\Contracts;

use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;

/** Host-derived current principal and tenant for workflow operations; never authored input. */
interface WorkflowActionContextProvider
{
    public function current(): WorkflowActionContext;
}
