<?php

namespace Splicewire\Beam\Workflows\Control;

use InvalidArgumentException;

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
 * @phpstan-type Guard callable(object): (bool|string)
 */
class GuardRegistry
{
    /** @var array<string, callable> */
    protected array $guards = [];

    /**
     * @param  callable(object): (bool|string)  $guard
     */
    public function register(string $ref, callable $guard): static
    {
        $this->guards[$ref] = $guard;

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
}
