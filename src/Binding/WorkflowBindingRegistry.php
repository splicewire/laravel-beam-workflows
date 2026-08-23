<?php

namespace Splicewire\Beam\Workflows\Binding;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

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
#[IsRegistry(
    root: 'beam.workflows.bindings',
    of: 'typeKey → Binding mappings (presence IS the enable), resolved by type',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Presence is the enable and absence is the disable, so an empty registry is meaningful state '
        .'rather than a miss to paper over — which is why this is Optional and a read returns null.',
    order: 32,
)]
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
     * Resolve an object straight to its binding: the type-identity candidates (ticket 01,
     * most-specific first) filtered through this seam — the FIRST candidate that has a binding wins.
     * So a schema-driven record bound at the schema level uses that workflow, while an unbound schema
     * falls back to its class-level binding. `null` when no candidate is bound (⇒ unmanaged).
     */
    public function forObject(object $object, TypeIdentityResolver $resolver): ?Binding
    {
        foreach ($resolver->candidatesFor($object) as $typeKey) {
            if ($this->has($typeKey)) {
                return $this->for($typeKey);
            }
        }

        return null;
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
