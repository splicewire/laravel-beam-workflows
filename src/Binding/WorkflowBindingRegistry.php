<?php

namespace Splicewire\Beam\Workflows\Binding;

use Psr\Log\LoggerInterface;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Forgettable;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/**
 * The socket's SEAM (PRD v2 §2, `the-seam-is-a-registry`): `typeKey → Binding`. This is where a
 * type becomes *managed* — a binding row existing IS the enable; its absence IS the disable. There
 * is no boolean flag, no env var: the presence of a key here is the whole switch.
 *
 * Exactly one lifecycle governs one type. Re-binding a
 * type REPLACES the prior binding (last write wins) and is logged, so a double-bind is observable
 * rather than a silent multi-bind. Multi-binding a type is a *different seam kind* the PRD defers.
 *
 * The generic fallback lives here too: {@see for()} on an unbound type returns `null`, which the
 * lifecycle reads as "unmanaged" — today's behaviour for everything without a binding. Nothing
 * downstream special-cases Composition; Composition is simply the first registered entry.
 *
 * ## Conformed, and the one thing to know about the keyspace (registry-kernel 38)
 *
 * The entries live in a held {@see BasicRegistry} and {@see unbind()} is {@see Forgettable}'s
 * `forget()` — this registry is torn down and re-hydrated per tenant by the host, which is exactly
 * the case `Forgettable` exists for.
 *
 * ⚠️ **The type keys are a FOREIGN identifier space and `Key`'s grammar now polices it.** Every key
 * live in the estate is legal (`composition`, `anchor.review`, `role_assignment`, `page`), but
 * tower's bind endpoint documents type keys as possibly *"a schema identifier [that] may contain
 * slashes"* — and `/` is not a registry-key character. Nothing produces such a key today (the
 * `SchemaTypeProjector` is the mapping point and defaults to disowning unmapped schema types), so
 * this is a latent constraint, not a live break: the projector is where a schema identity must be
 * mapped onto a legal workflow-type key. READS tolerate an illegal key as a miss; a WRITE with one
 * fails loudly rather than storing an entry no read could ever address.
 *
 * @implements Registry<Binding>
 */
#[IsRegistry(
    root: 'beam.workflows.bindings',
    entryType: Binding::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    description: 'typeKey → Binding mappings (presence IS the enable), resolved by type. Presence is the enable and absence is the disable, so an empty registry is meaningful state rather than a miss to paper over — which is why this is Optional and a read returns null. Type keys are a foreign identifier space (host `workflowType()` strings and projected schema types), so `Key`\'s grammar is a real constraint on them — see the class docblock.',
    order: 32,
)]
class WorkflowBindingRegistry implements Forgettable, Gated, Registry
{
    protected BasicRegistry $entries;

    public function __construct(
        protected ?LoggerInterface $logger = null,
    ) {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * The contract's write. `$entry` is a {@see Binding}, which carries its own `typeKey` — so a
     * self-keying single-argument call (`register($binding)`) works too, the same unpack every
     * conformed port in the estate does.
     *
     * @param  Binding|mixed  $entry
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof Binding) {
            $entry = $key;
            $key = $key->typeKey;
        }

        $this->logReplacement((string) $key, $entry);

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * Bind a type to a definition lineage (+ guard params). A second bind of the
     * same type replaces the first and is logged — never silently accumulated.
     *
     * This port's own vocabulary, kept as sugar over {@see register()}: it is what every host, every
     * hydrator and both admin endpoints in the estate call.
     *
     * @param  array<string, mixed>  $params
     */
    public function bind(string $typeKey, string $lineageRef, array $params = []): static
    {
        return $this->register($typeKey, new Binding($typeKey, $lineageRef, $params));
    }

    /**
     * Remove a type's binding ⇒ the type falls back to unmanaged. Live objects keep their pinned
     * marking until explicitly migrated (ticket 06); this only stops NEW objects being governed.
     */
    public function unbind(string $typeKey): static
    {
        return $this->forget($typeKey);
    }

    public function forget(RegistryKey|string $key): static
    {
        if ($this->addressable($key)) {
            $this->entries->forget($key);
        }

        return $this;
    }

    public function forgetBy(string $registrant): static
    {
        $this->entries->forgetBy($registrant);

        return $this;
    }

    /**
     * Whether `$typeKey` is bound. An illegal key answers `false` — type keys arrive from host
     * `workflowType()` implementations (`BeamUxEntry` can return the empty string for an untyped
     * entry) and from tenant-persisted binding rows, where "not bound" has always been the answer.
     */
    public function has(RegistryKey|string $key): bool
    {
        return $this->addressable($key) && $this->entries->has($key);
    }

    /**
     * The binding governing a type key, or `null` (⇒ unmanaged — the generic fallback). This port's
     * older spelling of {@see tryResolve()}.
     */
    public function for(string $typeKey): ?Binding
    {
        return $this->tryResolve($typeKey);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->addressable($key) ? $this->entries->tryResolve($key) : null;
    }

    /** @return list<Binding> */
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
     * Rebuilt from `relativeKeys()`, so the keys are the bare type keys a host wrote (not
     * `beam.workflows.bindings.*`) and the order is registration order rather than a PHP array's
     * insertion order that happened to coincide with it.
     *
     * @return array<string, Binding>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->entries->relativeKeys() as $typeKey) {
            $out[$typeKey] = $this->entries->resolve($typeKey);
        }

        return $out;
    }

    /** Log a binding replacement, which is the one thing `bind()` did beyond storing. */
    protected function logReplacement(string $typeKey, mixed $entry): void
    {
        if ($this->logger === null || ! $entry instanceof Binding) {
            return;
        }

        $existing = $this->tryResolve($typeKey);

        if ($existing instanceof Binding) {
            $this->logger->info(
                "Workflow binding for type [{$typeKey}] replaced.",
                ['from' => $existing->lineageRef, 'to' => $entry->lineageRef],
            );
        }
    }

    /**
     * Whether `$key` can address anything here at all — an illegal key holds nothing.
     *
     * ⚠️ Reads through {@see RelativeUriKey}, not {@see Key}, and that is registry-kernel 58 D5 rather
     * than a widening. A host type key is spelled `acme/press-release`, and `/` is not a `Key`
     * character — so the guard this replaced answered **false for every real binding**, quietly making
     * the whole registry unaddressable while every test that used a dotted fixture passed. `Key` is
     * still the floor: `RelativeUriKey` translates the slash into segments each of which must satisfy
     * `Key`'s own grammar, so the widening is in what can be ADDRESSED, never in what a segment may
     * contain — and the translation is lossless in both directions, which D5 made the requirement
     * because the same string is the `kind` discriminator clients parse back.
     */
    protected function addressable(RegistryKey|string $key): bool
    {
        return ! is_string($key) || RelativeUriKey::tryParse($key) !== null;
    }
}
