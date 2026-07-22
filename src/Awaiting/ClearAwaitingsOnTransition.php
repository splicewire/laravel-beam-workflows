<?php

namespace Splicewire\Beam\Workflows\Awaiting;

use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;
use Splicewire\Beam\Workflows\Control\LifecycleService;

/**
 * Clears stale awaiting rows when a subject LEAVES a place (beam-workflows-ux ticket 07). Listens to
 * {@see WorkflowTransitioned} and clears every awaiting row at the transition's `from` places — for
 * EVERY transition, effect-bearing or not, so a move off an awaiting place always sheds its rows even
 * when the destination attaches no `workflow.await` effect.
 *
 * MUST stay SYNCHRONOUS — it deliberately does NOT implement `ShouldQueue`. This is a hard ordering
 * constraint: {@see LifecycleService::react()} dispatches the event
 * BEFORE it runs the transition's effects, so this clear must complete during dispatch — before the
 * {@see AwaitEffect} stamp — otherwise a `from`/`to` place overlap (a self-loop, or re-entering a place)
 * would clear the row the stamp just wrote, or stamp then clear it. Clear-then-stamp yields a fresh
 * `created_at`; the reverse loses the row.
 *
 * Inert until the host binds an {@see AwaitingStore} (ticket 12); an empty `from` is a no-op.
 */
class ClearAwaitingsOnTransition
{
    public function __construct(protected Container $container) {}

    public function handle(WorkflowTransitioned $event): void
    {
        if ($event->from === [] || ! $this->container->bound(AwaitingStore::class)) {
            return;
        }

        $this->container->make(AwaitingStore::class)
            ->clearForPlaces($event->subject, $event->from);
    }
}
