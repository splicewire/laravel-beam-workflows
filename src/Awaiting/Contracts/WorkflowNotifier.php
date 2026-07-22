<?php

namespace Splicewire\Beam\Workflows\Awaiting\Contracts;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;

/**
 * The host-bound, stamp-time email delivery the `workflow.await` effect fires once per transition
 * (beam-workflows-ux tickets 01/07).
 *
 * The package hands over the opaque `principals` verbatim and the opaque `$actor` token; the HOST does
 * EVERYTHING identity-touching — resolve each principal to its users, set-union across principals,
 * subtract the actor's own user (self-exclusion), and send the `mail`-channel notification (queued).
 * The package never sees a resolved `User` (identity co-location). Ships no binding of its own (ticket
 * 13); until the host binds one, the effect's notify arm is inert.
 */
interface WorkflowNotifier
{
    /**
     * Deliver the stamp-time email for one applied transition to everyone the `$principals` resolve to
     * (deduped), minus the `$actor`'s own user. A null `$actor` subtracts nothing (a system path).
     *
     * @param  list<string>  $principals  Opaque `kind:selector` tokens.
     */
    public function notify(array $principals, Model $subject, WorkflowTransitioned $event, ?string $actor): void;
}
