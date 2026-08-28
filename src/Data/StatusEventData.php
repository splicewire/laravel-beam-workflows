<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\Activitylog\Contracts\Activity;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One row of a persisted ADR-0098 Display timeline — the READ twin of
 * {@see \Splicewire\Beam\Workflows\Display\StatusEvent}.
 *
 * `StatusEvent` is what a producer EMITS; {@see \Splicewire\Beam\Workflows\Display\StatusEmitter}
 * projects it onto the activity log, and this is what a projection reads back off that log. Same five
 * fields (`ref`, `state`, `message`, `progress`, `at`) plus the two the substrate adds: the log row's
 * own `id`, and the `runId` that groups one run's rows.
 *
 * `state` is the SHARED {@see \Splicewire\Beam\Workflows\Display\State} vocabulary
 * (`queued|running|complete|failed|skipped`), so a UI renders a per-row glyph off `state` directly
 * instead of decoding a `_active|_complete|_failed` name suffix.
 *
 * Homed here rather than in a consumer because the vocabulary is this package's: `splicewire/tower`
 * carried the only copy (`Splicewire\Tower\Data\StatusEventData`), which put the read shape of an
 * ADR-0098 timeline above every package that emits one — so `laravel-beam-tenancy` could not project
 * its own tenant's status timeline without naming tower. Tower's copy retires with its `tenants`
 * declaration (particle-contribution-seam ticket 15).
 */
#[TypeScript]
class StatusEventData extends BeamData
{
    public function __construct(
        public string $id,
        public string $state,
        public ?string $message = null,
        public ?string $ref = null,
        public ?ProgressData $progress = null,
        public ?string $runId = null,
        public ?string $at = null,
    ) {}

    /**
     * Project a persisted status Activity row (`log_name` = the configured status log) into the Display
     * shape. The normalized fields live on `properties` (ref/state/progress/run_id); `event` mirrors
     * `state` and `description` mirrors `message`.
     */
    public static function fromActivity(Activity $activity): self
    {
        $properties = $activity->properties ?? collect();
        $progress = $properties['progress'] ?? null;

        return new self(
            id: (string) $activity->getKey(),
            state: $properties['state'] ?? (string) $activity->event,
            message: $activity->description,
            ref: $properties['ref'] ?? null,
            progress: is_array($progress) && isset($progress['done'], $progress['total'])
                ? new ProgressData(done: (int) $progress['done'], total: (int) $progress['total'])
                : null,
            runId: $properties['run_id'] ?? null,
            at: $activity->created_at?->toIso8601String(),
        );
    }
}
