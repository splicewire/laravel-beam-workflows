<?php

namespace Splicewire\Beam\Workflows\Display;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;
use Splicewire\Beam\Workflows\Display\Events\StatusEmitted;

/**
 * The emit API (Seam A): project a {@see StatusEvent} onto the activity-log timeline and fire the
 * broadcast signal. This is the ONLY way status enters the substrate — producers call `emit()`;
 * they never write status into the artifact they are generating (the load-bearing invariant).
 *
 * Column mapping onto activitylog (v5):
 *   - `log_name`    = the stable `status` channel (config `beam-workflows.status_log_name`)
 *   - `description` = the event `message`
 *   - `event`       = the `state` value (indexed, for `where('event', 'complete')`-style queries)
 *   - `properties`  = { ref, state, progress, run_id }
 *   - `created_at`  = the event `at`
 *   - `subject`     = the process/model whose status this is
 *
 * Run grouping: activitylog **v5 removed the `batch_uuid` column** (batch system dropped), so the
 * per-run identifier rides `properties.run_id` (key configurable). All events from one run share
 * it, so a UI queries `properties->run_id = ?` to render one run's timeline coherently. This is a
 * deliberate deviation from the PRD's `batch_uuid` wording, forced by the installed vendor version;
 * the observable behavior (run grouping) is preserved, which is what the user stories require.
 *
 * Display is lossy-OK and fire-and-forget: an emit failure must never break the producer's work.
 * Control (the authoritative marking) lives in the process's own state machine, never here.
 */
class StatusEmitter
{
    public function __construct(
        protected Config $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Emit a status event. `$runId` groups every event of one run (null = ungrouped).
     * Returns the persisted Activity (or null if activitylog logging is disabled).
     */
    public function emit(?Model $subject, StatusEvent $event, ?string $runId = null): ?Activity
    {
        $logName = $this->config->get('beam-workflows.status_log_name', 'status');
        $runIdKey = $this->config->get('beam-workflows.run_id_key', 'run_id');

        $properties = $event->properties();
        if ($runId !== null) {
            $properties[$runIdKey] = $runId;
        }

        $logger = activity($logName)
            ->event($event->state->value)
            ->withProperties($properties)
            ->createdAt($event->at());

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $activity = $logger->log($event->message ?? $event->state->value);

        // The single broadcast/SSE seam that ends the UI's poll loops. Dispatched always (so
        // in-process listeners + tests observe it); only reaches a broadcaster when enabled.
        $this->events->dispatch(new StatusEmitted(
            $subject,
            $event,
            $runId,
            $activity?->getKey(),
        ));

        return $activity;
    }
}
