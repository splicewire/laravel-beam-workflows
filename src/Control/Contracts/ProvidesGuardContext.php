<?php

namespace Splicewire\Beam\Workflows\Control\Contracts;

use Splicewire\Beam\Workflows\Control\LifecycleService;

/**
 * An OPTIONAL hook a managed model implements to supply its own guard inputs (beam-workflows v2 —
 * the generic actuation seam). The record-specific facts a guard needs (e.g. "how many cells are
 * still stale") belong on the model, not in a per-model controller. The generic {@see
 * \Splicewire\Beam\Workflows\Control\WorkflowActuator} reads this so a host never writes a bespoke
 * endpoint just to assemble guard context.
 *
 * Binding-level params (e.g. `require_review`) are NOT here — those ride the binding and are merged
 * by the {@see LifecycleService}. This is only the per-record
 * context.
 */
interface ProvidesGuardContext
{
    /**
     * The record-specific guard inputs, merged under the binding params at decision time.
     *
     * @return array<string, mixed>
     */
    public function workflowGuardContext(): array;
}
