<?php

namespace Splicewire\Beam\Workflows\Actions\Contracts;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;

interface WorkflowActionAuthority
{
    /** Resolve the recorded principal, current tenant and ability; fail closed on any mismatch. */
    public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void;
}
