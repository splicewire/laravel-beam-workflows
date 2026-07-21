<?php

namespace Splicewire\Beam\Workflows\Display;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The single normalized status shape every producer emits (Seam A).
 *
 *   StatusEvent { ref, state, message, progress, at }
 *
 * - `ref`      stable address of an internal sub-part of the process (per-cell, per-URL,
 *              per-rule). NULL addresses the whole process/run. This is what lets per-internal
 *              status be tracked WITHOUT welding it onto the artifact's JSON.
 * - `state`    one shared {@see State} (queued|running|complete|failed|skipped).
 * - `message`  human-readable, first-class (was Spatie's `reason`).
 * - `progress` optional {@see Progress} — present iff the work is determinate.
 * - `at`       when it happened.
 *
 * The load-bearing invariant: producers EMIT this; they never store status INTO the artifact they
 * are generating. The event is projected onto the activity-log timeline by {@see StatusEmitter}.
 */
readonly class StatusEvent
{
    public function __construct(
        public State $state,
        public ?string $ref = null,
        public ?string $message = null,
        public ?Progress $progress = null,
        public ?DateTimeInterface $at = null,
    ) {}

    /**
     * A status event for the whole process/run (`ref` = null).
     */
    public static function whole(State $state, ?string $message = null, ?Progress $progress = null, ?DateTimeInterface $at = null): self
    {
        return new self($state, null, $message, $progress, $at);
    }

    /**
     * A status event for an addressable sub-part of the process.
     */
    public static function for(string $ref, State $state, ?string $message = null, ?Progress $progress = null, ?DateTimeInterface $at = null): self
    {
        return new self($state, $ref, $message, $progress, $at);
    }

    /**
     * When the event happened, defaulting to now() at read time if the producer did not stamp it.
     */
    public function at(): DateTimeInterface
    {
        return $this->at ?? Carbon::now();
    }

    /**
     * The activity-log `properties` bag: { ref, state, progress }. `message` rides `description`
     * and `at` rides `created_at`, so they are deliberately NOT duplicated here.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'ref' => $this->ref,
            'state' => $this->state->value,
            'progress' => $this->progress?->toArray(),
        ];
    }
}
