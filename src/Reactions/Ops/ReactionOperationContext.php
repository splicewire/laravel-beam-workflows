<?php

namespace Splicewire\Beam\Workflows\Reactions\Ops;

use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionContextProvider;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;

class ReactionOperationContext
{
    public static function current(): WorkflowActionContext
    {
        return app(WorkflowActionContextProvider::class)->current();
    }
}
