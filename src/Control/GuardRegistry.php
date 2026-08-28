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
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

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
 * ## The catalog is a SIDECAR, not a second keyspace (registry-kernel 38)
 *
 * Conforming folded `$guards` into a held {@see BasicRegistry}; `$catalog` stayed a plain array.
 * That is deliberate and it is not the "second array over one keyspace" the sweep escalates on: it
 * encodes no precedence and no resolution rule, it is written only ever in lockstep with the entry
 * inside {@see register()}, and it holds display metadata the RUNTIME never reads. What it is NOT
 * allowed to be is a way around the gate — so {@see guardCatalog()} is rebuilt from
 * `relativeKeys()`, which means a guard hidden from a caller is absent from the menu too rather
 * than leaking its name and param schema through the sidecar.
 *
 * @phpstan-type Guard callable(object): (bool|string)
 * @phpstan-type CatalogEntry array{name: string, label: string, paramsSchema: array<string, mixed>}
 *
 * @implements Registry<callable>
 */
#[IsRegistry(
    root: 'beam.workflows.guards',
    of: 'transition guard callables by reference, with editor-menu catalog entries',
    arity: RegistryArity::PickOne,
    entryType: 'callable',
    onDuplicate: OnDuplicate::Supersede,
    note: 'entryType is `callable`, not an FQCN: the ENTRY is a `callable(object): (bool|string)` and '
        .'hosts register closures, first-class callables and invokable objects interchangeably. The '
        .'catalog entry beside it (name/label/paramsSchema) is display metadata, not a second entry.',
    order: 33,
)]
class GuardRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    /** @var array<string, array{name: string, label: string, paramsSchema: array<string, mixed>}> */
    protected array $catalog = [];

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Register a guard closure under a reference, plus its catalog entry. `label` defaults to a
     * humanised form of the ref; `paramsSchema` is a JSON-Schema (default: no params). Registering
     * always creates a catalog entry, so catalog membership and {@see has()} coincide.
     *
     * ## Why `label` and `paramsSchema` moved to slots 5 and 6
     *
     * The contract fixes slots 3 and 4 as `$by` (the registrant) and `$ability` (the gate token),
     * and PHP forbids narrowing either. The port's own two arguments therefore sit AFTER them.
     *
     * ⚠️ **`label:` and `paramsSchema:` MUST be passed by name.** This docblock previously claimed
     * every live caller in the estate already did, "verified across `splicewire/tower`,
     * `splicewire/laravel-beam-ux` and the app" — that verification set was not the estate.
     * `splicewire/laravel-satellite-training` passed both POSITIONALLY, so the move landed a
     * `paramsSchema` array in `$ability` and `~/Herd/audiostud` could not run `php artisan` at all.
     * Re-swept 2026-08-27 across every package `src` and `tests` dir, every Herd host `app` dir and
     * every starter: satellite-training was the only positional caller, and it is fixed. Sweep the
     * real paths before believing a claim of this shape again — a symlink-view grep from the
     * ecosystem root reports zero and exits successfully.
     *
     * @param  callable(object): (bool|string)|mixed  $guard
     * @param  array<string, mixed>  $paramsSchema
     */
    public function register(
        RegistryKey|string $key,
        mixed $guard = null,
        ?string $by = null,
        ?string $ability = null,
        ?string $label = null,
        array $paramsSchema = [],
    ): static {
        $this->entries->register($key, $guard, $by, $ability);

        $ref = (string) $key;

        $this->catalog[$ref] = [
            'name' => $ref,
            'label' => $label ?? Str::headline($ref),
            'paramsSchema' => $paramsSchema,
        ];

        return $this;
    }

    /**
     * Whether a guard is registered under `$ref`.
     *
     * An illegal key answers `false` rather than throwing. Guard refs arrive here from EDITOR-AUTHORED
     * blueprint data by way of {@see \Splicewire\Beam\Workflows\Blueprint\BlueprintValidator}, whose
     * entire job is to answer "is this reference in the catalog?" with a validation error — a malformed
     * ref is the loudest case it exists to reject, and it must not become a 500 on the save path.
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
     * The guard at `$ref` — this port's older spelling of {@see resolve()}.
     *
     * ⚠️ A miss now throws the kernel's `RegistryMiss` (a `RuntimeException`) rather than this
     * package's `InvalidArgumentException`. Every live caller guards with {@see has()} first.
     *
     * @return callable(object): (bool|string)
     */
    public function get(string $ref): callable
    {
        return $this->resolve($ref);
    }

    /**
     * The full guard catalog — what the editor renders as the pickable menu of guards + their param
     * schemas. A list, in registration order.
     *
     * Driven off `relativeKeys()` rather than off the sidecar's own insertion order, so the menu is
     * exactly the set of guards the caller can see and cannot outrun the gate.
     *
     * @return list<array{name: string, label: string, paramsSchema: array<string, mixed>}>
     */
    public function guardCatalog(): array
    {
        $out = [];

        foreach ($this->entries->relativeKeys() as $ref) {
            if (isset($this->catalog[$ref])) {
                $out[] = $this->catalog[$ref];
            }
        }

        return $out;
    }

    /**
     * One guard's catalog entry, or `null` if unknown.
     *
     * @return array{name: string, label: string, paramsSchema: array<string, mixed>}|null
     */
    public function catalogEntry(string $ref): ?array
    {
        return $this->has($ref) ? ($this->catalog[$ref] ?? null) : null;
    }

    /** Whether `$key` can address anything here at all — an illegal key holds nothing. */
    protected function addressable(RegistryKey|string $key): bool
    {
        return ! is_string($key) || Key::tryParse($key) !== null;
    }
}
