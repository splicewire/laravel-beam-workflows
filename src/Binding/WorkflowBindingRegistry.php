<?php

namespace Splicewire\Beam\Workflows\Binding;

use Psr\Log\LoggerInterface;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/**
 * The socket's SEAM (PRD v2 §2, `the-seam-is-a-registry`): `typeKey → Binding`. This is where a
 * type becomes *managed* — a binding row existing IS the enable; its absence IS the disable. There
 * is no boolean flag, no env var: the presence of a key here is the whole switch.
 *
 * **Pick-one arity**, declared not assumed: exactly one lifecycle governs one type. Re-binding a
 * type REPLACES the prior binding (last write wins) and is logged, so a double-bind is observable
 * rather than a silent multi-bind. Multi-binding a type is a *different seam kind* the PRD defers.
 *
 * The generic fallback lives here too: {@see for()} on an unbound type returns `null`, which the
 * lifecycle reads as "unmanaged" — today's behaviour for everything without a binding. Nothing
 * downstream special-cases Composition; Composition is simply the first registered entry.
 */
class WorkflowBindingRegistry
{
    /** @var array<string, Binding> */
    protected array $bindings = [];

    public function __construct(
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * Bind a type to a definition lineage (+ guard params). Pick-one arity: a second bind of the
     * same type replaces the first and is logged — never silently accumulated.
     *
     * @param  array<string, mixed>  $params
     */
    public function bind(string $typeKey, string $lineageRef, array $params = []): static
    {
        if (isset($this->bindings[$typeKey])) {
            $this->logger?->info(
                "Workflow binding for type [{$typeKey}] replaced (pick-one arity).",
                ['from' => $this->bindings[$typeKey]->lineageRef, 'to' => $lineageRef],
            );
        }

        $this->bindings[$typeKey] = new Binding($typeKey, $lineageRef, $params);

        return $this;
    }

    /**
     * Remove a type's binding ⇒ the type falls back to unmanaged. Live objects keep their pinned
     * marking until explicitly migrated (ticket 06); this only stops NEW objects being governed.
     */
    public function unbind(string $typeKey): static
    {
        unset($this->bindings[$typeKey]);

        return $this;
    }

    public function has(string $typeKey): bool
    {
        return isset($this->bindings[$typeKey]);
    }

    /**
     * The binding governing a type key, or `null` (⇒ unmanaged — the generic fallback).
     */
    public function for(string $typeKey): ?Binding
    {
        return $this->bindings[$typeKey] ?? null;
    }

    /**
     * Resolve an object straight to its binding: type-identity door (ticket 01) then this seam.
     * `null` when the object is untyped OR its type is unbound — both are unmanaged.
     */
    public function forObject(object $object, TypeIdentityResolver $resolver): ?Binding
    {
        $typeKey = $resolver->forObject($object);

        return $typeKey !== null ? $this->for($typeKey) : null;
    }

    /**
     * Every registered binding, keyed by type — the source for the Workflows admin surface (09).
     *
     * @return array<string, Binding>
     */
    public function all(): array
    {
        return $this->bindings;
    }
}
