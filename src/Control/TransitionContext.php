<?php

namespace Splicewire\Beam\Workflows\Control;

use Splicewire\Beam\Workflows\Display\StatusEvent;

/**
 * The ambient context of a single transition attempt — the host-supplied facts about *who* drove a
 * transition and *which run* it belongs to, threaded verbatim through the Control seam. The engine is
 * OPAQUE to both:
 *
 *   - `actor` is an opaque `kind:selector` token (e.g. `user:42`, `service:migrator`) the HOST stamps
 *     and the HOST resolves. The engine never calls `Auth::user()` and never dereferences the token —
 *     identity co-location (beam-workflows-ux ticket 05): a workflow resolves its actor inside the
 *     service that owns its users, never cross-service. A system/queue path passes `null`.
 *   - `runId` groups every Display {@see StatusEvent} of one run so a
 *     UI can render that run's timeline coherently.
 *
 * This is a clean replacement of the shipped `?string $runId` positional param on
 * {@see LifecycleService::transition()} (pre-1.0 co-dev, no external consumers): the actor rides
 * alongside the run id in one value object instead of a growing positional param list.
 */
readonly class TransitionContext
{
    public function __construct(
        public ?string $actor = null,
        public ?string $runId = null,
    ) {}
}
