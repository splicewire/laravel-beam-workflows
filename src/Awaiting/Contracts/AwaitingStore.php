<?php

namespace Splicewire\Beam\Workflows\Awaiting\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The host-bound projection the `workflow.await` effect writes to (beam-workflows-ux tickets 07/09).
 *
 * OPAQUE and IDENTITY-FREE: every method takes the subject model + an opaque `kind:selector`
 * `principal` string (e.g. `owner:`, `role:editor`, `watcher:`) which the store persists VERBATIM.
 * The package never resolves a principal to a `User`, never reads one back (the inbox arm queries the
 * host's own Eloquent model directly — ticket 14), and ships no binding of its own: the host binds a
 * concrete store over its tenant table (ticket 12). Until it does, the effect is inert.
 *
 * Write-only, three verbs: `stamp` on enter (idempotent), `clearForPlaces` on leave, `clearForSubject`
 * for hygiene / the event-bypassing migration path (which never re-stamps — awaitings can't be
 * reprojected).
 */
interface AwaitingStore
{
    /**
     * Record that `$principal` is awaiting `$subject` at `$place`. Idempotent **insert-or-ignore** on
     * the natural key `(subject, place, principal)` — a re-enter preserves the original enter time.
     * The optional parent morph is ticket 09's host-supplied rollup key (null = a flat, ungrouped row).
     */
    public function stamp(Model $subject, string $place, string $principal, ?string $parentType = null, ?string $parentId = null): void;

    /**
     * Clear every awaiting row for `$subject` at any of `$places` — the clear-on-leave motion. An empty
     * `$places` is a no-op.
     *
     * @param  list<string>  $places
     */
    public function clearForPlaces(Model $subject, array $places): void;

    /**
     * Clear ALL awaiting rows for `$subject`, regardless of place — hygiene and the migration path
     * (which calls this, never re-stamps).
     */
    public function clearForSubject(Model $subject): void;
}
