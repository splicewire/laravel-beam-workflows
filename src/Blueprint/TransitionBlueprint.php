<?php

namespace Splicewire\Beam\Workflows\Blueprint;

use InvalidArgumentException;

/**
 * One transition described as data (part of a {@see WorkflowBlueprint}).
 *
 * `from` / `to` are always lists of place names — a transition may consume/produce several places
 * at once (workflow-net), never modelled as a scalar. `guard` is a *reference* (a name a guard
 * registry resolves to a callable) rather than inline PHP, so the blueprint stays storable and
 * validatable; the actual guard logic is wired downstream (the node / lifecycle), reading this
 * reference from the built definition's transition metadata.
 *
 * `effects` are references too — names of registered post-transition effects (a notification, a
 * webhook) that run AFTER the transition applies. Same code-only line as guards: the definition
 * names an effect, never carries its logic. Author-set effect params ride `metadata.effect_params`
 * keyed by effect name.
 */
class TransitionBlueprint
{
    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @param  list<string>  $effects
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public array $from,
        public array $to,
        public ?string $guard = null,
        public array $effects = [],
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['name'], $data['from'], $data['to'])) {
            throw new InvalidArgumentException('A transition blueprint requires name, from and to.');
        }

        return new self(
            name: (string) $data['name'],
            from: array_values(array_map('strval', (array) $data['from'])),
            to: array_values(array_map('strval', (array) $data['to'])),
            guard: isset($data['guard']) ? (string) $data['guard'] : null,
            effects: array_values(array_map('strval', (array) ($data['effects'] ?? []))),
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * The author-set params for one effect ref (from `metadata.effect_params`), or an empty bag.
     *
     * @return array<string, mixed>
     */
    public function effectParams(string $effectRef): array
    {
        $params = $this->metadata['effect_params'][$effectRef] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'from' => $this->from,
            'to' => $this->to,
            'guard' => $this->guard,
            'effects' => $this->effects,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * The metadata bag attached to this transition in the built Definition — the guard reference
     * plus any custom metadata, so downstream can read `getTransitionMetadata($t)['guard']`.
     *
     * @return array<string, mixed>
     */
    public function definitionMetadata(): array
    {
        return array_filter(
            array_merge($this->metadata, ['guard' => $this->guard]),
            fn ($v) => $v !== null,
        );
    }
}
