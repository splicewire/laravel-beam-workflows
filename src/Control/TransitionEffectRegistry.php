<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Support\Str;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
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
 * Conformed onto the popcorn kernel (registry-kernel 38) exactly as its sibling {@see GuardRegistry}
 * was, catalog sidecar and all — read that class's docblock for why the sidecar is not a second
 * keyspace and why the catalog read runs through `relativeKeys()`.
 *
 * @phpstan-type Effect callable(WorkflowTransitioned, array<string, mixed>): void
 *
 * @implements Registry<callable>
 */
#[IsRegistry(
    root: 'beam.workflows.effects',
    entryType: 'callable',
    onDuplicate: OnDuplicate::Supersede,
    description: 'post-transition effect callables by reference, with catalog entries. entryType is `callable`, not an FQCN: the ENTRY is a `callable(WorkflowTransitioned, array): void` and hosts register closures and invokable objects (`AwaitEffect`) interchangeably at the same key.',
    order: 34,
)]
class TransitionEffectRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    /** @var array<string, array{name: string, label: string, paramsSchema: array<string, mixed>}> */
    protected array $catalog = [];

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register an effect under a reference, plus its catalog entry.
     *
     * `label` and `paramsSchema` sit in slots 5 and 6 because the contract owns 3 and 4 — see
     * {@see GuardRegistry::register()}, which carries the full account. They MUST be passed by
     * name. Unlike the guard registry this signature cannot fail loudly on a positional `label`:
     * it lands in `$by`, which is also `?string`, so the call type-checks and the label is simply
     * lost. `splicewire/laravel-satellite-training` did exactly that until 2026-08-27 and nothing
     * reported it — the sibling positional `paramsSchema` on the guard side is what surfaced it.
     *
     * @param  callable(WorkflowTransitioned, array<string, mixed>): void|mixed  $effect
     * @param  array<string, mixed>  $paramsSchema
     */
    public function register(
        RegistryKey|string $key,
        mixed $effect = null,
        ?string $by = null,
        ?string $ability = null,
        ?string $label = null,
        array $paramsSchema = [],
    ): static {
        $this->entries->register($key, $effect, $by, $ability);

        $ref = (string) $key;

        $this->catalog[$ref] = [
            'name' => $ref,
            'label' => $label ?? Str::headline($ref),
            'paramsSchema' => $paramsSchema,
        ];

        return $this;
    }

    /**
     * Whether an effect is registered under `$ref`. An illegal key answers `false` rather than
     * throwing — effect refs come from editor-authored blueprint data, which the validator rejects
     * with a message rather than a fatal.
     */
    public function has(RegistryKey|string $key): bool
    {
        return $this->addressable($key) && $this->entries->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->addressable($key) ? $this->entries->tryResolve($key) : null;
    }

    /** @return list<callable> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The effect at `$ref` — this port's older spelling of {@see resolve()}.
     *
     * ⚠️ A miss now throws the kernel's `RegistryMiss` rather than `InvalidArgumentException`.
     *
     * @return callable(WorkflowTransitioned, array<string, mixed>): void
     */
    public function get(string $ref): callable
    {
        return $this->resolve($ref);
    }

    /**
     * The effect catalog — the pickable menu of effects + their param schemas for the editor.
     *
     * @return list<array{name: string, label: string, paramsSchema: array<string, mixed>}>
     */
    public function effectCatalog(): array
    {
        $out = [];

        foreach ($this->entries->relativeKeys() as $ref) {
            if (isset($this->catalog[$ref])) {
                $out[] = $this->catalog[$ref];
            }
        }

        return $out;
    }

    /** Whether `$key` can address anything here at all — an illegal key holds nothing. */
    protected function addressable(RegistryKey|string $key): bool
    {
        return ! is_string($key) || Key::tryParse($key) !== null;
    }
}
