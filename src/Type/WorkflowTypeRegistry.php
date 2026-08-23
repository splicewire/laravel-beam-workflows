<?php

namespace Splicewire\Beam\Workflows\Type;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

use Illuminate\Support\Str;
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
    note: 'RunAll because the read that matters is the whole enumeration behind an admin dropdown — so '
        .'keys() ordering is OBSERVABLE BY A USER, which is why registration order being guaranteed is not '
        .'a detail here.',
    order: 31,
)]
class WorkflowTypeRegistry
{
    /** @var array<string, string> key => label */
    protected array $types = [];

    public function register(string $key, ?string $label = null): static
    {
        $this->types[$key] = $label ?? Str::headline($key);

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function label(string $key): ?string
    {
        return $this->types[$key] ?? null;
    }

    /**
     * Every registered type as `{ key, label }`, stable-ordered by registration — the option list a
     * binding-config dropdown renders.
     *
     * @return list<array{key: string, label: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->types as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }
}
