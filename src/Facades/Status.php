<?php

namespace Splicewire\Beam\Workflows\Facades;

use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Workflows\Display\StatusManager;

/**
 * The Status facade — the short front door to the Display emit seam (beam-facade tickets 24, 32).
 *
 * It holds NO logic: every method it appears to have resolves through `__callStatic` to the
 * container-bound {@see StatusManager}, which composes the {@see \Splicewire\Beam\Workflows\Display\StatusEvent}
 * and hands it to the {@see \Splicewire\Beam\Workflows\Display\StatusEmitter}. The emitter stays
 * separately injectable for the two provider sites that need it directly.
 *
 * This is the family's FIRST sibling facade, and it replaces a static-only class — the shape core
 * spent beam-facade 01–19 deleting. Nothing here is a template to copy: a family package reaches for
 * a facade on its own evidence (see `splicewire-beam-runbook/references/package-layout.md`), and the
 * only rule that binds unconditionally is that a static forwarder over a container binding is either
 * a real facade or plain DI, never the third thing.
 *
 * Deliberately NOT registered as a global alias (`extra.laravel.aliases`): every call site imports
 * this class explicitly, so a bare `\Status` can never become a second, import-free way to say
 * `Status::running()` that `surgeon:trace` cannot see.
 *
 * The `@method` block below is hand-written and guarded by a reflective parity test
 * (`tests/Facade/StatusFacadeParityTest.php`), which asserts it matches the manager's public methods.
 *
 * @method static ?\Spatie\Activitylog\Contracts\Activity emit(?\Illuminate\Database\Eloquent\Model $subject, \Splicewire\Beam\Workflows\Display\StatusEvent $event, ?string $runId = null)
 * @method static ?\Spatie\Activitylog\Contracts\Activity queued(?\Illuminate\Database\Eloquent\Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?\DateTimeInterface $at = null)
 * @method static ?\Spatie\Activitylog\Contracts\Activity running(?\Illuminate\Database\Eloquent\Model $subject, ?string $message = null, ?string $ref = null, ?\Splicewire\Beam\Workflows\Display\Progress $progress = null, ?string $runId = null, ?\DateTimeInterface $at = null)
 * @method static ?\Spatie\Activitylog\Contracts\Activity complete(?\Illuminate\Database\Eloquent\Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?\DateTimeInterface $at = null)
 * @method static ?\Spatie\Activitylog\Contracts\Activity failed(?\Illuminate\Database\Eloquent\Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?\DateTimeInterface $at = null)
 * @method static ?\Spatie\Activitylog\Contracts\Activity skipped(?\Illuminate\Database\Eloquent\Model $subject, ?string $message = null, ?string $ref = null, ?string $runId = null, ?\DateTimeInterface $at = null)
 *
 * @see StatusManager
 */
class Status extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatusManager::class;
    }
}
