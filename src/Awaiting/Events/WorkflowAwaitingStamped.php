<?php

namespace Splicewire\Beam\Workflows\Awaiting\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the moment a NEW `workflow.await` row lands — i.e. the DB-idempotent `insertOrIgnore` actually
 * inserted (a re-enter it skips does NOT fire this). The generic "someone now has something waiting on
 * them" signal a host can react to — e.g. to push a review-inbox refresh — WITHOUT reaching into the
 * opaque await places or the raw-insert store. The awaiting table is written via `insertOrIgnore` and
 * fires no Eloquent model event, so this is the only clean hook for "a new awaiting appeared".
 */
class WorkflowAwaitingStamped
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $subject,
        public string $place,
        public string $principal,
        public ?string $parentType = null,
        public ?string $parentId = null,
    ) {}
}
