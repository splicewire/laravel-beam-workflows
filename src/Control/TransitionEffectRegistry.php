<?php

namespace Splicewire\Beam\Workflows\Control;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;

/**
 * The EFFECT catalog (beam-workflows v2) — the notification side of the guard pattern. A transition
 * can reference **effects** by name (a notification, a webhook, …); the effect is registered code
 * that runs AFTER the transition applies. Exactly like guards: definition data references an effect
 * by name + fills its params, and can never carry executable logic. Each registered effect
 * advertises a catalog entry (name, label, params schema) so the editor can attach one from a menu.
 *
 * An effect receives the {@see WorkflowTransitioned} event and its author-set params. It is
 * fire-and-forget (a Display-side reaction) — an effect that throws must not roll back the Control
 * change, so the runtime isolates each one.
 *
 * @phpstan-type Effect callable(WorkflowTransitioned, array<string, mixed>): void
 */
#[IsRegistry(
    root: 'beam.workflows.effects',
    of: 'post-transition effect callables by reference, with catalog entries',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    order: 34,
)]
class TransitionEffectRegistry
{
    /** @var array<string, callable> */
    protected array $effects = [];

    /** @var array<string, array{name: string, label: string, paramsSchema: array<string, mixed>}> */
    protected array $catalog = [];

    /**
     * @param  callable(WorkflowTransitioned, array<string, mixed>): void  $effect
     * @param  array<string, mixed>  $paramsSchema
     */
    public function register(string $ref, callable $effect, ?string $label = null, array $paramsSchema = []): static
    {
        $this->effects[$ref] = $effect;
        $this->catalog[$ref] = [
            'name' => $ref,
            'label' => $label ?? Str::headline($ref),
            'paramsSchema' => $paramsSchema,
        ];

        return $this;
    }

    public function has(string $ref): bool
    {
        return isset($this->effects[$ref]);
    }

    /**
     * @return callable(WorkflowTransitioned, array<string, mixed>): void
     */
    public function get(string $ref): callable
    {
        return $this->effects[$ref]
            ?? throw new InvalidArgumentException("No transition effect registered for reference [{$ref}].");
    }

    /**
     * The effect catalog — the pickable menu of effects + their param schemas for the editor.
     *
     * @return list<array{name: string, label: string, paramsSchema: array<string, mixed>}>
     */
    public function effectCatalog(): array
    {
        return array_values($this->catalog);
    }
}
