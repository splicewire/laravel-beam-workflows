<?php

namespace Splicewire\Beam\Workflows\Control\Events;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Display\Events\StatusEmitted;

/**
 * A STRUCTURED domain event fired once per APPLIED transition on a managed model (beam-workflows
 * v2). Unlike the Display {@see StatusEmitted} — which is
 * coarse (the transition name rides a message string, and it fires for the node too) — this carries
 * the transition as data, so a host can react precisely: "on `submit_for_review`, notify the
 * reviewers." Notifications, webhooks, and other reactions listen to THIS.
 *
 * It is fired only on the managed-object path (the {@see \Splicewire\Beam\Workflows\Control\
 * LifecycleService}), where a subject model + a pinned version exist — never for the stateless
 * Circuit node.
 *
 * @property list<string> $from
 * @property list<string> $to
 */
class WorkflowTransitioned
{
    /**
     * @param  list<string>  $from  Places the marking left.
     * @param  list<string>  $to  Places the marking entered (the new marking).
     * @param  string|null  $actor  The host-supplied opaque `kind:selector` token for who drove this
     *                              transition (e.g. `user:42`), or null for a system/queue path. The
     *                              engine forwards it verbatim; a notify effect resolves + self-excludes
     *                              it host-side (identity co-location, beam-workflows-ux ticket 05).
     */
    public function __construct(
        public Model $subject,
        public string $transition,
        public array $from,
        public array $to,
        public ?string $versionId = null,
        public ?string $runId = null,
        public ?string $actor = null,
    ) {}
}
