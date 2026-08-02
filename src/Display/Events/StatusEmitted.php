<?php

namespace Splicewire\Beam\Workflows\Display\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Splicewire\Beam\Workflows\Display\StatusEvent;

/**
 * Fired once per emitted {@see StatusEvent} — the single broadcast/SSE seam that ends the UI's
 * poll loops (Seam A). A frontend subscribes to the subject's channel and receives status pushes
 * instead of polling a status endpoint.
 *
 * This is a *Display* signal: fire-and-forget, lossy-OK. It carries no authority — a listener may
 * drop it, replay it, or reshape it without any risk to execution correctness, because Control
 * lives in the process's own marking store, never in this event.
 *
 * Broadcasting is gated on `beam.workflows.broadcast` so the package is inert (dispatch-only, no
 * transport) until a host opts in and configures a broadcaster. The event is always *dispatched*
 * (so in-process listeners and tests observe it); it only reaches the broadcaster when enabled.
 */
class StatusEmitted implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ?Model $subject,
        public StatusEvent $status,
        public ?string $runId = null,
        public ?int $activityId = null,
        public ?string $actor = null,
    ) {}

    public function broadcastWhen(): bool
    {
        return (bool) config('beam.workflows.broadcast', false);
    }

    /**
     * One channel per subject (e.g. `status.App.Models.Composition.42`), so a UI subscribes to
     * exactly the process it is viewing. Whole-process and by-`ref` events share the subject's
     * channel; the `ref` discriminates inside the payload.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->subject === null) {
            return [new Channel('status')];
        }

        $key = str_replace('\\', '.', $this->subject::class).'.'.$this->subject->getKey();

        return [new Channel('status.'.$key)];
    }

    public function broadcastAs(): string
    {
        return 'status.emitted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ref' => $this->status->ref,
            'state' => $this->status->state->value,
            'message' => $this->status->message,
            'progress' => $this->status->progress?->toArray(),
            'at' => $this->status->at()->format(DATE_ATOM),
            'run_id' => $this->runId,
            // The single opaque "who" — deliberately broadcast (subscribers are already authed); the
            // Display side resolves the token → name at read time (beam-workflows-ux ticket 05).
            'actor' => $this->actor,
        ];
    }
}
