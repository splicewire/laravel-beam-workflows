<?php

namespace Splicewire\Beam\Workflows\Type\Concerns;

use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged as WorkflowManagedContract;

/**
 * Sane defaults for the {@see WorkflowManagedContract}: a consuming model only has to name its
 * `workflowType()` key; the marking projects onto `status` and the version pin lives on
 * `workflow_version` unless the model overrides them.
 *
 * The trait deliberately does NOT default `workflowType()` — the type key is the object's identity,
 * not a convention, so it stays abstract and each model declares it explicitly.
 */
trait WorkflowManaged
{
    /**
     * The stable workflow-type key. Left abstract on purpose — identity is declared, not defaulted.
     */
    abstract public function workflowType(): string;

    public function workflowStatusAttribute(): string
    {
        return 'status';
    }

    public function workflowVersionAttribute(): string
    {
        return 'workflow_version';
    }
}
