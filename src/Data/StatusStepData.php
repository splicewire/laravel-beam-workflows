<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One step of a status run, as a timeline draws it: per-STEP state folded from the ADR-0098 event log
 * (app-walkthrough APP-06, APP-10, FINDINGS 15). The raw log only ever says a step STARTED (`running`), so drawn as-is
 * a finished run looks stuck. {@see fold()} closes what the log implies is closed; the raw events stay available as
 * History.
 */
#[TypeScript]
class StatusStepData extends BeamData
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(
        /** The step's ref on the log (`StatusEventData::$ref`), e.g. a provisioning step name. */
        public string $ref,
        /** What the log said when the step started. */
        public string $label,
        /** running | done | failed */
        public string $state,
    ) {}

    /**
     * Fold the NEWEST run of a timeline (events in chronological order) into its steps, in the order they started:
     *
     * - a step is `done` once a later step starts, or once the run's terminal `complete` arrives;
     * - the step open at a terminal `failed` is `failed`;
     * - only the newest step of an unfinished run is `running`.
     *
     * Earlier runs are not folded: they are History.
     *
     * @param  list<StatusEventData>  $events
     * @return list<self>
     */
    public static function fold(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $run = $events[array_key_last($events)]->runId;
        /** @var array<string, self> $steps */
        $steps = [];
        $open = null;

        foreach ($events as $event) {
            if ($event->runId !== $run) {
                continue;
            }

            if ($event->state === 'running' && $event->ref !== null) {
                if ($open !== null && $open !== $event->ref) {
                    $steps[$open]->state = self::DONE;
                }
                $steps[$event->ref] ??= new self($event->ref, (string) ($event->message ?? $event->ref), self::RUNNING);
                $steps[$event->ref]->state = self::RUNNING;
                $open = $event->ref;
            } elseif ($event->state === 'complete') {
                foreach ($steps as $step) {
                    if ($step->state === self::RUNNING) {
                        $step->state = self::DONE;
                    }
                }
                $open = null;
            } elseif ($event->state === 'failed') {
                if ($open !== null) {
                    $steps[$open]->state = self::FAILED;
                }
                $open = null;
            }
        }

        return array_values($steps);
    }
}
