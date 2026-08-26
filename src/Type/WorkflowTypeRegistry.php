<?php

namespace Splicewire\Beam\Workflows\Type;

use Illuminate\Support\Str;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;

/**
 * A catalog of the workflow-type KEYS a host knows about (beam-workflows v2). The
 * {@see TypeIdentityResolver} maps an object → its key, but it cannot ENUMERATE keys — a type key is
 * declared per model (`WorkflowManaged::workflowType()`), not centrally. This registry is that
 * missing enumeration: a host registers each governable type (key + human label) so the binding
 * admin can offer a dropdown of types instead of a free-text field.
 *
 * It is purely a discovery aid for the UI. It is NOT the binding switch (that is the
 * {@see WorkflowBindingRegistry}): a type can be registered here
 * yet unbound (⇒ unmanaged), and a binding can exist for a type never registered here.
 */
#[IsRegistry(
    root: 'beam.workflows.types',
    of: 'governable workflow types (key + label) for the admin dropdown',
    arity: RegistryArity::RunAll,
    onDuplicate: OnDuplicate::Supersede,
    entryType: 'string',
    note: 'RunAll because the read that matters is the whole enumeration behind an admin dropdown — so '
        .'keys() ordering is OBSERVABLE BY A USER, which is why registration order being guaranteed is not '
        .'a detail here. The ENTRY is the human label; the type key is the address, which is why '
        .'entryType is a plain string rather than a class.',
    order: 31,
)]
class WorkflowTypeRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register a governable type. The label is the ENTRY; the type key is the address.
     *
     * `$entry` is widened to `mixed` by the contract but stays a `?string` label in practice — a null
     * label derives one from the key, which is the whole convenience this method had before it
     * conformed. Contract callers spelling `register($key, $label, by: …)` get the same thing.
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry ?? Str::headline((string) $key), $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($key);
    }

    /** The label at `$key`, or null — this port's older spelling of {@see tryResolve()}. */
    public function label(string $key): ?string
    {
        return $this->tryResolve($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

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
     * Every registered type as `{ key, label }`, in registration order — the option list a
     * binding-config dropdown renders.
     *
     * The ordering is not this class's to keep any more: `keys()` guarantees registration order
     * (ticket 08 D4), which is exactly the property this registry needed and had to trust a PHP array
     * for. Keys come back RELATIVE, because the dropdown's `value` is the bare type key a host wrote,
     * not `beam.workflows.types.*`.
     *
     * @return list<array{key: string, label: string}>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->entries->relativeKeys() as $key) {
            $out[] = ['key' => $key, 'label' => (string) $this->entries->resolve($key)];
        }

        return $out;
    }
}
