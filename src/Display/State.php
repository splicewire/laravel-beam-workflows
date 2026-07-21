<?php

namespace Splicewire\Beam\Workflows\Display;

/**
 * The ONE shared status vocabulary (Seam A).
 *
 * This enum retires the ~5 drifting copies scattered across the app — `RenderStatus`
 * (pending/completed/failed), `TenantSyncStatus` (Pending/Running/Completed/Failed),
 * `RunFragmentUrlBatchStatusEnum` (queued/processing/complete/failed), and friends — each of
 * which re-declared the same lifecycle with cosmetic drift. Every producer now speaks these five
 * cases, so a status color map / icon chain / poll loop is written once, not per surface.
 *
 * This is a *Display* vocabulary (what a run reports it is doing), NOT a Control vocabulary (what
 * transition is legal). Control lives in each process's own state machine (symfony/workflow
 * marking); a `State` is the projection of a Control change onto the timeline.
 *
 * Broad migration of the existing enums onto this one is an out-of-scope fast-follow — this ships
 * the shared word; it does not rewrite every caller.
 */
enum State: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Complete = 'complete';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * A terminal state is one a run does not leave — nothing further will be emitted for this ref
     * once it reports terminal. `Queued`/`Running` are in-flight; the rest are terminal.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Complete, self::Failed, self::Skipped => true,
            self::Queued, self::Running => false,
        };
    }
}
