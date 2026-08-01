<?php

namespace Splicewire\Beam\Workflows\Awaiting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\Events\WorkflowAwaitingStamped;

/**
 * The default Eloquent implementation of the opaque {@see AwaitingStore} contract
 * (beam-workflows-ux ticket 12), over the tenant `workflow_awaitings` table.
 *
 * Write-only and identity-blind: it persists the opaque `principal` token verbatim and never resolves
 * a user (ticket 02) — the inbox arm reads the table directly (ticket 14). `stamp` is DB-level
 * idempotent (insert-or-ignore on the unique natural key, so a re-enter preserves the original enter
 * time); the clears are prefix deletes over that same key.
 */
class EloquentAwaitingStore implements AwaitingStore
{
    public function stamp(Model $subject, string $place, string $principal, ?string $parentType = null, ?string $parentId = null): void
    {
        // insertOrIgnore on the unique (subject, place, principal) key: idempotent at the DB level, so a
        // repeat enter keeps the first `created_at` and never throws on the duplicate.
        $inserted = WorkflowAwaiting::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'place' => $place,
            'principal' => $principal,
            'parent_type' => $parentType,
            'parent_id' => $parentId,
            'created_at' => Date::now(),
        ]);

        // Fire ONLY when a row actually landed (insertOrIgnore returns 0 on the skipped re-enter), so a
        // host signal — a review-inbox refresh, a notification — fires once per genuinely-new awaiting.
        if ($inserted > 0) {
            WorkflowAwaitingStamped::dispatch($subject, $place, $principal, $parentType, $parentId);
        }
    }

    public function clearForPlaces(Model $subject, array $places): void
    {
        if ($places === []) {
            return;
        }

        WorkflowAwaiting::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereIn('place', array_values($places))
            ->delete();
    }

    public function clearForSubject(Model $subject): void
    {
        WorkflowAwaiting::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->delete();
    }
}
