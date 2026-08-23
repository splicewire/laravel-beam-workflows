<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * Resolves a transition's guard *reference* (a string carried in the blueprint) to an actual guard
 * callable. Keeping guards in a registry — rather than inline in the blueprint — is what lets a
 * definition stay pure, storable data (ticket 08) while the guard logic (e.g. "no cell left
 * `Stale`") lives in host PHP.
 *
 * A guard callable receives the workflow subject and returns:
 *   - `true`            → the transition is allowed;
 *   - `false` | string  → the transition is BLOCKED (a string is surfaced as the reason).
 *
 * **Guard CATALOG (ticket 05) — the code-only security line.** Every registered guard also
 * advertises a catalog entry: a stable `name`, a human `label`, and a JSON-Schema `paramsSchema`
 * describing the knobs it accepts. The `<WorkflowEditor>` (08) attaches a guard *from this catalog*
 * and fills its params — it never authors logic. The load-bearing invariant: stored/edited data can
 * only ever reference a guard by name and supply params; there is NO code path by which blueprint
 * data introduces an executable guard closure. Guards are code; the catalog is the menu.
 *
 * @phpstan-type Guard callable(object): (bool|string)
 * @phpstan-type CatalogEntry array{name: string, label: string, paramsSchema: array<string, mixed>}
 */
#[IsRegistry(
    root: 'beam.workflows.guards',
    of: 'transition guard callables by reference, with editor-menu catalog entries',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    order: 33,
)]
class GuardRegistry
{
    /** @var array<string, callable> */
    protected array $guards = [];

    /** @var array<string, array{name: string, label: string, paramsSchema: array<string, mixed>}> */
    protected array $catalog = [];

    /**
     * Register a guard closure under a reference, plus its catalog entry. `label` defaults to a
     * humanised form of the ref; `paramsSchema` is a JSON-Schema (default: no params). Registering
     * always creates a catalog entry, so catalog membership and {@see has()} coincide.
     *
     * @param  callable(object): (bool|string)  $guard
     * @param  array<string, mixed>  $paramsSchema
     */
    public function register(string $ref, callable $guard, ?string $label = null, array $paramsSchema = []): static
    {
        $this->guards[$ref] = $guard;
        $this->catalog[$ref] = [
            'name' => $ref,
            'label' => $label ?? Str::headline($ref),
            'paramsSchema' => $paramsSchema,
        ];

        return $this;
    }

    public function has(string $ref): bool
    {
        return isset($this->guards[$ref]);
    }

    /**
     * @return callable(object): (bool|string)
     */
    public function get(string $ref): callable
    {
        return $this->guards[$ref]
            ?? throw new InvalidArgumentException("No guard registered for reference [{$ref}].");
    }

    /**
     * The full guard catalog — what the editor renders as the pickable menu of guards + their param
     * schemas. A list, stable-ordered by registration.
     *
     * @return list<array{name: string, label: string, paramsSchema: array<string, mixed>}>
     */
    public function guardCatalog(): array
    {
        return array_values($this->catalog);
    }

    /**
     * One guard's catalog entry, or `null` if unknown.
     *
     * @return array{name: string, label: string, paramsSchema: array<string, mixed>}|null
     */
    public function catalogEntry(string $ref): ?array
    {
        return $this->catalog[$ref] ?? null;
    }
}
