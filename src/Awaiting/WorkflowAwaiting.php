<?php

namespace Splicewire\Beam\Workflows\Awaiting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One durable "waiting on you" row (beam-workflows-ux tickets 07/09), tenant-scoped. Stamped by the
 * generic `workflow.await` effect when a subject enters an awaiting place, cleared on leave — the
 * pull-surface twin of the per-event `mail`, read by the Review inbox arm (ticket 14).
 *
 * OPAQUE by design: `principal` is a `kind:selector` token (`owner:`, `role:reviewer`, `watcher:`),
 * never a resolved user — the "everything awaiting me" query inverts per principal kind at read time.
 * Rows are immutable: created on enter, deleted on leave (so there is no `updated_at`). The nullable
 * `parent` morph is ticket 09's host-supplied rollup key (null = a flat singleton).
 *
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $place
 * @property string $principal
 * @property string|null $parent_type
 * @property string|null $parent_id
 * @property Carbon|null $created_at
 */
class WorkflowAwaiting extends Model
{
    use HasUuids;

    protected $table = 'workflow_awaitings';

    protected $guarded = [];

    /** Rows are created/deleted only — enter time is the sole timestamp. */
    public const UPDATED_AT = null;

    /**
     * The subject the principal is awaiting (a Composition, a ConceptAnchor, …).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The optional rollup parent (ticket 09) — host-supplied, resolved read-time.
     */
    public function parent(): MorphTo
    {
        return $this->morphTo();
    }
}
