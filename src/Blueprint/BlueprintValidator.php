<?php

namespace Splicewire\Beam\Workflows\Blueprint;

use InvalidArgumentException;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;

/**
 * Validates a {@see WorkflowBlueprint} before it is written as a definition version (ticket 08 save
 * path). Two checks:
 *
 *   1. Referential integrity — every transition's from/to and the initial marking name a declared
 *      place (already enforced by {@see WorkflowBlueprint::assertReferentialIntegrity()}; re-run here
 *      so a single call is the whole gate).
 *   2. Guard-catalog membership (ticket 05) — every `guard` ref on a transition MUST exist in the
 *      {@see GuardRegistry} catalog. An unknown guard name is a save-time validation error, not a
 *      run-time surprise. This is the code-only security line: stored data may reference a guard by
 *      name and supply params, but can never introduce guard logic — an unregistered name is simply
 *      rejected.
 */
class BlueprintValidator
{
    public function __construct(
        protected GuardRegistry $guards,
        protected TransitionEffectRegistry $effects,
    ) {}

    /**
     * @throws InvalidArgumentException on a bad place reference, an unknown guard, or an unknown effect.
     */
    public function validate(WorkflowBlueprint $blueprint): void
    {
        $blueprint->assertReferentialIntegrity();

        foreach ($blueprint->transitions as $transition) {
            if ($transition->guard !== null && ! $this->guards->has($transition->guard)) {
                throw new InvalidArgumentException(
                    "Transition [{$transition->name}] references guard [{$transition->guard}], which is not "
                    .'in the guard catalog. Guards are code, referenced by name — register it, or pick one '
                    .'from the catalog.',
                );
            }

            foreach ($transition->effects as $effect) {
                if (! $this->effects->has($effect)) {
                    throw new InvalidArgumentException(
                        "Transition [{$transition->name}] references effect [{$effect}], which is not in the "
                        .'effect catalog. Effects are code, referenced by name — register it, or pick one from '
                        .'the catalog.',
                    );
                }
            }
        }
    }

    /**
     * Non-throwing variant: the list of validation errors (empty ⇒ valid). Handy for a form that
     * wants to surface field errors rather than catch an exception.
     *
     * @return list<string>
     */
    public function errors(WorkflowBlueprint $blueprint): array
    {
        try {
            $this->validate($blueprint);

            return [];
        } catch (InvalidArgumentException $e) {
            return [$e->getMessage()];
        }
    }
}
