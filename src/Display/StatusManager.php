<?php

namespace Splicewire\Beam\Workflows\Display;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * The composition layer over {@see StatusEmitter} for the emit-never-store precedent (Seam A): the
 * five state helpers build a {@see StatusEvent} and hand it to the emitter, so a producer names a
 * state and a message rather than assembling an event.
 *
 * Reached through {@see \Splicewire\Beam\Workflows\Facades\Status}, which is the only front door
 * consumers use; nothing here is static, and the emitter is constructor-injected (it is bound
 * `singleton` in `BeamWorkflowsServiceProvider`, so pinning one costs nothing).
 *
 * Every helper is Display-only: it records what the run reports, and never decides what is legal.
 * Control — the authoritative marking — lives in the process's own state machine, never here.
 */
class StatusManager
{
    public function __construct(
        protected StatusEmitter $emitter,
    ) {}

    /**
     * Emit an already-built event (the full-control path). Prefer the state helpers below for the
     * common cases.
     */
    public function emit(?Model $subject, StatusEvent $event, ?string $runId = null): ?Activity
    {
        return $this->emitter->emit($subject, $event, $runId);
    }

    public function queued(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return $this->of(State::Queued, $subject, $message, $ref, null, $runId, $at);
    }

    public function running(?Model $subject, ?string $message = null, ?string $ref = null, ?Progress $progress = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return $this->of(State::Running, $subject, $message, $ref, $progress, $runId, $at);
    }

    public function complete(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return $this->of(State::Complete, $subject, $message, $ref, null, $runId, $at);
    }

    public function failed(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return $this->of(State::Failed, $subject, $message, $ref, null, $runId, $at);
    }

    public function skipped(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return $this->of(State::Skipped, $subject, $message, $ref, null, $runId, $at);
    }

    protected function of(State $state, ?Model $subject, ?string $message, ?string $ref, ?Progress $progress, ?string $runId, ?DateTimeInterface $at): ?Activity
    {
        return $this->emit($subject, new StatusEvent($state, $ref, $message, $progress, $at), $runId);
    }
}
