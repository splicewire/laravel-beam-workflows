<?php

namespace Splicewire\Beam\Workflows\Awaiting\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Splicewire\Beam\Workflows\Awaiting\Contracts\WorkflowNotifier;

/**
 * The generic `mail` notification a host's {@see WorkflowNotifier}
 * sends for a workflow subject that has no designed, type-specific email (beam-workflows-ux ticket 13).
 * A host may substitute its own type-specific email; every other managed type falls back to this.
 *
 * Queued + `mail`-only (ticket 01: the delivery surface is email + the durable inbox; there is no
 * `notifications` table / `database` channel).
 */
class WorkflowTransitionedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $to  The places the subject entered.
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public string $transition,
        public array $to,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = Str::of($this->subjectType)->afterLast('\\')->headline();
        $place = $this->to[0] ?? 'a new state';

        return (new MailMessage)
            ->subject("{$label} update: {$this->readableTransition()}")
            ->line("A {$label} you're following moved to \"{$place}\".")
            ->line('This is waiting on you.');
    }

    protected function readableTransition(): string
    {
        return (string) Str::of($this->transition)->replace('_', ' ')->title();
    }
}
