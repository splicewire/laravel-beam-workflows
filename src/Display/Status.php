<?php

namespace Splicewire\Beam\Workflows\Display;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/**
 * The ergonomic entry point for the emit-never-store precedent (Seam A). A thin static facade over
 * {@see StatusEmitter} so a producer reads as `Status::running($model, 'generating…')` rather than
 * injecting the emitter everywhere — this is the copy-this shape Beam consumers imitate.
 *
 * Every helper is Display-only: it records what the run reports, and never decides what is legal.
 */
class Status
{
    protected static function emitter(): StatusEmitter
    {
        return app(StatusEmitter::class);
    }

    /**
     * Emit an already-built event (the full-control path). Prefer the state helpers below for the
     * common cases.
     */
    public static function emit(?Model $subject, StatusEvent $event, ?string $runId = null): ?Activity
    {
        return static::emitter()->emit($subject, $event, $runId);
    }

    public static function queued(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return static::of(State::Queued, $subject, $message, $ref, null, $runId, $at);
    }

    public static function running(?Model $subject, ?string $message = null, ?string $ref = null, ?Progress $progress = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return static::of(State::Running, $subject, $message, $ref, $progress, $runId, $at);
    }

    public static function complete(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return static::of(State::Complete, $subject, $message, $ref, null, $runId, $at);
    }

    public static function failed(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return static::of(State::Failed, $subject, $message, $ref, null, $runId, $at);
    }

    public static function skipped(?Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?DateTimeInterface $at = null): ?Activity
    {
        return static::of(State::Skipped, $subject, $message, $ref, null, $runId, $at);
    }

    protected static function of(State $state, ?Model $subject, ?string $message, ?string $ref, ?Progress $progress, ?string $runId, ?DateTimeInterface $at): ?Activity
    {
        return static::emit($subject, new StatusEvent($state, $ref, $message, $progress, $at), $runId);
    }
}
