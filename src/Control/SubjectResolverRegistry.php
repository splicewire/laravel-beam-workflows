<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Model;

/**
 * The host seam that makes workflow actuation GENERIC (beam-workflows v2). A record is addressed
 * over HTTP by a `kind` slug + id (`workflow-subjects/{kind}/{id}`); this registry maps a kind to a
 * finder that returns the managed model. A host wires a new managed type in one line —
 * `register('article', fn ($id) => Article::find($id))` — and the generic actuation surface works
 * for it, with no bespoke endpoint. The workflow TYPE is then derived from the resolved model
 * (ticket 01), so a single kind (`composition`) can still resolve to many workflow types (its class
 * key or a schema key).
 */
class SubjectResolverRegistry
{
    /** @var array<string, callable(string): ?Model> */
    protected array $resolvers = [];

    /**
     * @param  callable(string): ?Model  $resolver
     */
    public function register(string $kind, callable $resolver): static
    {
        $this->resolvers[$kind] = $resolver;

        return $this;
    }

    public function has(string $kind): bool
    {
        return isset($this->resolvers[$kind]);
    }

    /**
     * Resolve a `kind`+id to its managed model, or `null` when the kind is unregistered or the record
     * is not found.
     */
    public function resolve(string $kind, string $id): ?Model
    {
        $resolver = $this->resolvers[$kind] ?? null;

        return $resolver ? $resolver($id) : null;
    }

    /**
     * The registered subject kinds.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_keys($this->resolvers);
    }
}
