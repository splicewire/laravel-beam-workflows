<?php

namespace Splicewire\Beam\Workflows\Tests\Localization;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Splicewire\Beam\Workflows\Awaiting\Notifications\WorkflowTransitionedNotification;
use Splicewire\Beam\Workflows\Tests\TestCase;

class WorkflowMailTest extends TestCase
{
    public function test_real_mail_delivery_uses_notification_locale_and_restores_worker_locale_without_mutating_payload(): void
    {
        config(['mail.default' => 'array', 'mail.mailers.array.transport' => 'array',
            'mail.from.address' => 'workflow@example.test', 'mail.from.name' => 'Workflow',
            'beam.localization.pseudo' => true]);
        $messages = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$messages): void {
            $messages[] = [$event->message->getSubject(), $event->message->getHtmlBody()];
        });
        foreach ([['reviewed'], []] as $places) {
            foreach (['en', 'en-XA'] as $locale) {
                app()->setLocale('en');
                $notification = (new WorkflowTransitionedNotification('App\\Article', 'raw-id', 'publish', $places))->locale($locale);
                self::assertInstanceOf(ShouldQueue::class, $notification);
                $before = serialize($notification);
                Notification::sendNow((new AnonymousNotifiable)->route('mail', 'reader@example.test'), $notification);
                self::assertSame('en', app()->getLocale());
                // Laravel assigns a delivery id; the authored constructor fields remain byte-for-byte unchanged.
                self::assertSame(['App\\Article', 'raw-id', 'publish', $places],
                    [$notification->subjectType, $notification->subjectId, $notification->transition, $notification->to]);
                $restored = unserialize($before);
                self::assertSame($places, $restored->to);
                [$subject, $html] = $messages[array_key_last($messages)];
                $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($locale === 'en') {
                    self::assertSame('Article update: Publish', $subject);
                    self::assertStringContainsString('A Article you\'re following moved to "'.($places[0] ?? 'a new state').'".', $text);
                    self::assertStringContainsString('This is waiting on you.', $text);
                } else {
                    self::assertStringContainsString('⟦', $subject);
                    self::assertStringContainsString('⟦', $text);
                    self::assertStringNotContainsString('beam-workflows::', $text);
                }
            }
        }
        self::assertCount(4, $messages);
    }

    public function test_authored_place_is_escaped_by_real_markdown_rendering_and_never_translated_on_write(): void
    {
        $place = '<script>alert("raw")</script>';
        $notification = new WorkflowTransitionedNotification('App\\Article', 'raw-id', 'publish', [$place]);
        $mail = $notification->toMail(new AnonymousNotifiable);
        $html = (string) $mail->render();
        self::assertStringNotContainsString($place, $html);
        self::assertSame([$place], $notification->to);
        self::assertSame('A Article you\'re following moved to "'.$place.'".', $mail->introLines[0]);
    }
}
