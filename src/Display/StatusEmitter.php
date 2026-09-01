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
 *   - `log_name`    = the stable `status` channel (config `beam.workflows.status_log_name`)
 *   - `description` = the event `message`
 *   - `event`       = the `state` value (indexed, for `where('event', 'complete')`-style queries)
 *   - `properties`  = { ref, state, progress, run_id, actor }
 *   - `causer`      = ALWAYS null: the "who" is the opaque actor token on `properties.<actor_key>`,
 *                     never spatie's auto-`Auth::user()` causer (beam-workflows-ux ticket 05 — the
 *                     engine is opaque to identity, so it suppresses the auto-causer unconditionally
 *                     and the token is the single canonical representation of who drove the move)
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
    /** @var Dispatcher|(callable(): Dispatcher) */
    protected $events;

    /**
     * The dispatcher may be handed in as a RESOLVER rather than an instance, and the provider hands
     * one in.
     *
     * This class is a singleton, so capturing the dispatcher at construction bakes in whichever
     * instance existed at that moment — and `Event::fake()` works by REBINDING `events`, so a faked
     * dispatcher never reaches an emitter built before the fake. That was invisible only because
     * nothing resolved this during boot; the moment something did (registry-kernel ticket 40 fills
     * workflows' capability registry eagerly at `packageBooted()`), `Event::assertDispatched()`
     * started failing against events that really were dispatched — to the wrong dispatcher.
     *
     * Resolving per emit costs a container hit on a fire-and-forget path and removes a whole class
     * of order-dependent test lies.
     *
     * @param  Dispatcher|(callable(): Dispatcher)  $events
     */
    public function __construct(
        protected Config $config,
        Dispatcher|callable $events,
    ) {
        $this->events = $events;
    }

    protected function events(): Dispatcher
    {
        return $this->events instanceof Dispatcher ? $this->events : ($this->events)();
    }

    /**
     * Emit a status event. `$runId` groups every event of one run (null = ungrouped); `$actor` is the
     * host-supplied opaque `kind:selector` token for who drove the move (null = system/queue path).
     * Returns the persisted Activity (or null if activitylog logging is disabled).
     */
    public function emit(?Model $subject, StatusEvent $event, ?string $runId = null, ?string $actor = null): ?Activity
    {
        $logName = $this->config->get('beam.workflows.status_log_name', 'status');
        $runIdKey = $this->config->get('beam.workflows.run_id_key', 'run_id');
        $actorKey = $this->config->get('beam.workflows.actor_key', 'actor');

        $properties = $event->properties();
        if ($runId !== null) {
            $properties[$runIdKey] = $runId;
        }
        if ($actor !== null) {
            $properties[$actorKey] = $actor;
        }

        // Tenancy-awareness (Seam A): a subject whose status must land somewhere other than the
        // default (tenant-swapped) `activity_log` — e.g. a central-connection audit model so a
        // central subject's status is readable where the host reads it — maps its class to its own
        // Activity model. spatie resolves the model from `activitylog.activity_model` at log time,
        // so we scope-swap that config around the emit (config-cache safe: a class-string map, no
        // closures) and restore it after, leaving the host's ambient config untouched.
        $activity = $this->usingActivityModel($this->resolveActivityModel($subject), function () use ($logName, $event, $properties, $subject) {
            $logger = activity($logName)
                ->event($event->state->value)
                ->withProperties($properties)
                // The status log's "who" is the opaque actor token in properties, never spatie's
                // auto-`Auth::user()` causer — the engine is opaque to identity (ticket 05). Suppress the
                // auto-causer so there is exactly one canonical representation of who drove the move.
                ->causedByAnonymous()
                ->createdAt($event->at());

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            return $logger->log($event->message ?? $event->state->value);
        });

        // The single broadcast/SSE seam that ends the UI's poll loops. Dispatched always (so
        // in-process listeners + tests observe it); only reaches a broadcaster when enabled.
        $this->events()->dispatch(new StatusEmitted(
            $subject,
            $event,
            $runId,
            $activity?->getKey(),
            $actor,
        ));

        return $activity;
    }

    /**
     * Resolve the Activity model a subject's status should be written to. `beam.workflows.activity_models`
     * is a `subject-class => activity-model-class` map (matched by `instanceof`, so a subclass or
     * interface key works); `beam.workflows.activity_model` is a global default. Null = spatie's own
     * configured default (the tenant-swapped `activity_log`), i.e. no swap.
     *
     * The walk itself moved to {@see ActivityResidency} so the READ side can ask the same question of
     * the same map. It used to live here as a private method, which is why the `activity` particle
     * resource froze `CentralActivityLog` into its backing and could not see a tenant's own rows
     * (particle-manifest-repatriation ticket 09). Behaviour here is unchanged — this method is now a
     * one-line delegation, kept rather than inlined at the call site because it is `protected` and
     * `laravel-satellite-training`'s progress test subclasses this emitter.
     *
     * Resolved per emit, not per emitter: this class is a singleton, and the map is config a host may
     * legitimately change between requests (and a test between cases).
     */
    protected function resolveActivityModel(?Model $subject): ?string
    {
        return $this->residency()->forSubject($subject);
    }

    /**
     * The shared residency reader. Built from this emitter's OWN config repository rather than
     * container-resolved, so a caller that hands the emitter a scratch `Repository` (as the display
     * tests do) gets a residency reading that same repository instead of the ambient one.
     */
    protected function residency(): ActivityResidency
    {
        return new ActivityResidency($this->config);
    }

    /**
     * Run the emit with `activitylog.activity_model` scope-swapped to `$model`, restoring the ambient
     * value afterward. A null model means "no swap" — spatie uses the host's configured default.
     *
     * @template T
     *
     * @param  callable(): T  $emit
     * @return T
     */
    protected function usingActivityModel(?string $model, callable $emit): mixed
    {
        if ($model === null) {
            return $emit();
        }

        $previous = $this->config->get('activitylog.activity_model');
        $this->config->set('activitylog.activity_model', $model);

        try {
            return $emit();
        } finally {
            $this->config->set('activitylog.activity_model', $previous);
        }
    }
}
