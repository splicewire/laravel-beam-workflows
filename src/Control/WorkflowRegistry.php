<?php

namespace Splicewire\Beam\Workflows\Control;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

/**
 * A name → {@see WorkflowBlueprint} registry, so a node's config (or a lifecycle service) can name
 * a definition — `composition.lifecycle` — instead of carrying the whole blueprint inline. The one
 * place a host declares its workflows, shared by the Seam B node and the Seam C lifecycle so both
 * drive the SAME definition.
 *
 * Conformed onto the popcorn kernel (registry-kernel 38) over a held {@see BasicRegistry}: the
 * private array is gone and `get()` stays as this port's sugar over {@see resolve()}.
 *
 * ⚠️ **Blueprint names are DOTTED and that is now structural.** `composition.lifecycle` used to be
 * one opaque array key; it is now two key segments under `beam.workflows.blueprints`. Nothing in the
 * estate registers both `composition` and `composition.lifecycle`, and if something ever did, the
 * shallower name would name a BRANCH as well as a leaf — which `resolve()` answers with
 * `AmbiguousRegistryMatch` rather than silently picking one.
 *
 * @implements Registry<WorkflowBlueprint>
 */
#[IsRegistry(
    root: 'beam.workflows.blueprints',
    entryType: WorkflowBlueprint::class,
    onDuplicate: OnDuplicate::Supersede,
    description: 'named workflow blueprints (state machines), resolved by name. register() still accepts the array form and hydrates it through WorkflowBlueprint::fromArray(), so a host that declares a workflow as config data keeps working — the ENTRY is always a hydrated WorkflowBlueprint, never the raw array.',
    order: 30,
)]
class WorkflowRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register a blueprint under a name.
     *
     * Widened contravariantly for the contract (registry-kernel 38): `$blueprint` is `mixed` on the
     * signature and stays a `WorkflowBlueprint|array` in practice, hydrated at the top exactly as it
     * always was. Every historical `register($name, $blueprint)` caller is unchanged.
     *
     * @param  WorkflowBlueprint|array<string, mixed>|mixed  $blueprint
     */
    public function register(RegistryKey|string $key, mixed $blueprint = null, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register(
            $key,
            is_array($blueprint) ? WorkflowBlueprint::fromArray($blueprint) : $blueprint,
            $by,
            $ability,
        );

        return $this;
    }

    /**
     * Whether a blueprint is registered under `$name`.
     *
     * A string that is not a legal {@see Key} answers `false` rather than throwing. Blueprint names
     * reach here from stored binding rows and from editor-authored definition data, where "no such
     * workflow" has always been an ordinary miss — and {@see LifecycleService} reads this exact
     * method to decide whether an object is governed at all.
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

    /** @return list<WorkflowBlueprint> */
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
     * The blueprint registered under `$name` — this port's older spelling of {@see resolve()}.
     *
     * ⚠️ A miss now throws the kernel's `RegistryMiss` (a `RuntimeException`) rather than this
     * package's `InvalidArgumentException`. Nothing in the estate asserts on the old class; every
     * live caller guards with {@see has()} first.
     */
    public function get(string $name): WorkflowBlueprint
    {
        return $this->resolve($name);
    }

    /**
     * The registered blueprint names, in registration order — relative, i.e. the bare name a host
     * wrote, not `beam.workflows.blueprints.*`.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->entries->relativeKeys();
    }

    /** Whether `$key` can address anything here at all — an illegal key holds nothing. */
    protected function addressable(RegistryKey|string $key): bool
    {
        return ! is_string($key) || Key::tryParse($key) !== null;
    }
}
