<?php

namespace Splicewire\Beam\Workflows\Awaiting;

use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\Contracts\WorkflowNotifier;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;

/**
 * The ONE fully-generic transition effect (beam-workflows-ux ticket 07). Attached to a transition by
 * name (`workflow.await`) with an author-chosen set of recipient `principals` (the recipient picker,
 * ticket 08), it does the whole "waiting on you" motion in two deliveries:
 *
 *   1. **Email once** — `WorkflowNotifier::notify()` (the host resolves → unions → subtracts the actor
 *      → sends `mail`). Deduped at stamp time because notify is called once with all principals.
 *   2. **Inbox row per principal per entered place** — `AwaitingStore::stamp()` (a durable projection
 *      the Review inbox arm reads, ticket 14). One opaque row each; idempotent on the natural key.
 *
 * The effect is model- and identity-blind: it forwards opaque `kind:selector` principal strings and
 * the opaque `$event->actor` token verbatim, never touching a `User`. It supersedes the baked
 * `composition.notify_owner` example (= this effect with `principals: ['owner:']`).
 *
 * Both contracts are HOST-bound and OPTIONAL: with neither bound the effect is inert (the package
 * ships no store/notifier). Stamp is opt-in — only this effect stamps; the clear motion
 * ({@see ClearAwaitingsOnTransition}) always fires.
 */
class AwaitEffect
{
    /** The catalog reference a transition attaches this effect by. */
    public const REF = 'workflow.await';

    public function __construct(protected Container $container) {}

    /**
     * @param  array<string, mixed>  $params  The effect's author-set params (`effect_params[workflow.await]`).
     */
    public function __invoke(WorkflowTransitioned $event, array $params): void
    {
        $principals = array_values(array_filter(
            (array) ($params['principals'] ?? []),
            fn ($p) => is_string($p) && $p !== '',
        ));

        if ($principals === []) {
            return; // No recipients ⇒ nothing to await or notify.
        }

        // (1) Email once — the host does all identity work (resolve → union → subtract actor → mail).
        if ($this->container->bound(WorkflowNotifier::class)) {
            $this->container->make(WorkflowNotifier::class)
                ->notify($principals, $event->subject, $event, $event->actor);
        }

        // (2) One durable inbox row per (entered place, principal). Idempotent insert-or-ignore.
        if ($this->container->bound(AwaitingStore::class)) {
            $store = $this->container->make(AwaitingStore::class);
            foreach ($event->to as $place) {
                foreach ($principals as $principal) {
                    $store->stamp($event->subject, $place, $principal);
                }
            }
        }
    }

    /**
     * The catalog params schema — a single `principals` array of opaque `kind:selector` tokens. The
     * recipient-picker widget (ticket 08/17) authors it; the runtime reads
     * `effect_params[workflow.await].principals`.
     *
     * @return array<string, mixed>
     */
    public static function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'principals' => [
                    'type' => 'array',
                    'title' => 'Await / notify',
                    'description' => 'Who this transition notifies and adds a "waiting on you" inbox item for.',
                    'items' => ['type' => 'string'],
                    'default' => [],
                ],
            ],
            'required' => ['principals'],
        ];
    }
}
