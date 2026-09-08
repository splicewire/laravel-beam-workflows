<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Model;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * The host seam that makes workflow actuation GENERIC (beam-workflows v2). A record is addressed
 * over HTTP by a `kind` slug + id (`workflow-subjects/{kind}/{id}`); this registry maps a kind to a
 * finder that returns the managed model. A host wires a new managed type in one line —
 * `register('article', fn ($id) => Article::find($id))` — and the generic actuation surface works
 * for it, with no bespoke endpoint. The workflow TYPE is then derived from the resolved model
 * (ticket 01), so a single kind (`composition`) can still resolve to many workflow types (its class
 * key or a schema key).
 *
 * ## `resolve()` answers in two vocabularies, because the port and the kernel both own the name
 *
 * This is the one class in the package where the kernel's method name was already taken by a
 * different question (registry-kernel 38). `Registry::resolve($key)` means "the ENTRY at this key";
 * this port's `resolve($kind, $id)` means "the MODEL that kind+id names" — one argument apart, and
 * PHP will not let an implementation add a required parameter. So `$id` is optional and it selects
 * the reading: omit it and you get the resolver callable (the contract's answer); pass it and you
 * get the model or `null` (the port's answer, unchanged for every existing caller).
 *
 * The `null` on an unknown kind is load-bearing rather than lax: `$kind` is a raw URL segment, and
 * tower's `WorkflowSubjectController` turns a null into a 404. An unparseable kind is therefore also
 * a miss here, not an `InvalidRegistryKey` — a garbage slug in a URL must stay a 404, not become a
 * 500.
 *
 * @implements Registry<callable>
 */
#[IsRegistry(
    root: 'beam.workflows.subject-resolvers',
    entryType: 'callable',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'subject finders by kind slug (id → model) for generic actuation. entryType is `callable`: the ENTRY is a `callable(string): ?Model` a host closes over its own query with. resolve() with an $id INVOKES it rather than returning it — see the class docblock for why one method carries both readings.',
    order: 35,
)]
class SubjectResolverRegistry implements Gated, Registry
{
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * @param  callable(string): ?Model|mixed  $resolver
     */
    public function register(RegistryKey|string $key, mixed $resolver = null, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $resolver, $by, $ability);

        return $this;
    }

    /** Whether a resolver is registered for `$kind`. An illegal slug holds nothing, so: `false`. */
    public function has(RegistryKey|string $key): bool
    {
        return $this->addressable($key) && $this->entries->has($key);
    }

    /**
     * With `$id`: the managed model that `kind`+id names, or `null` when the kind is unregistered,
     * unparseable, or the record is not found — this port's reading, and what every existing caller
     * asks for.
     *
     * Without `$id`: the registered resolver callable at `$kind` — the kernel's reading.
     *
     * @return Model|callable|null
     */
    public function resolve(RegistryKey|string $key, ?string $id = null): mixed
    {
        $resolver = $this->addressable($key) ? $this->entries->tryResolve($key) : null;

        if ($id === null) {
            return $resolver;
        }

        return is_callable($resolver) ? $resolver($id) : null;
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
     * The registered subject kinds — relative, i.e. the bare slug a host wrote.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return $this->entries->relativeKeys();
    }

    /** Whether `$key` can address anything here at all — an illegal key holds nothing. */
    protected function addressable(RegistryKey|string $key): bool
    {
        return ! is_string($key) || Key::tryParse($key) !== null;
    }
}
