<?php

namespace Splicewire\Beam\Workflows\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;

/**
 * The advisory half of the single-place-persistence constraint
 * ({@see LifecycleService::unpersistable()}): report, BEFORE anyone attempts a transition, every
 * blueprint this host registers whose marking a lifecycle could not store.
 *
 * `LifecycleService` now refuses such a transition at run time with a blocker, which is honest but
 * late — the host finds out when a user clicks a button. This audit reads the declaration instead:
 * a transition declaring more than one `to` place, or an `initial` marking holding more than one
 * place, produces a multi-token marking that the project-onto-a-scalar-column path cannot represent.
 *
 * ADVISORY, and deliberately so, on two independent grounds:
 *
 *   1. **The population is the HOST's.** {@see WorkflowRegistry} holds whatever blueprints this host
 *      registered; the same package loaded elsewhere registers a different set. Per the estate rule,
 *      a check whose answer is a fact about the host is a finding, never a fatal.
 *   2. **A multi-place blueprint is not wrong.** It is only unpersistable *by a lifecycle*. The same
 *      blueprint run through the Control-seam node — where the marking rides the port envelope and
 *      the host owns persistence — is entirely correct, and this audit cannot see which seam a given
 *      blueprint is destined for. That is exactly why the constraint is not enforced in
 *      {@see \Splicewire\Beam\Workflows\Blueprint\BlueprintValidator}, which would forbid the legal
 *      case along with the illegal one.
 *
 * SCOPE, stated because a Pass here is narrower than it looks: this reads the CODE-REGISTERED
 * blueprints only. Blueprints stored as definition versions in the tenant
 * `workflow_definition_versions` table are not enumerable through `DefinitionStore` (it exposes
 * lookups, not a listing) and are per-tenant, so they are out of this audit's reach. The run-time
 * blocker is what covers them.
 */
class MultiPlaceMarkingAudit implements DoctorAudit
{
    public const CHECK = 'workflows.single-place-persistence';

    public function __construct(protected WorkflowRegistry $registry) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $names = $this->registry->names();

        if ($names === []) {
            return [Finding::inconclusive(
                self::CHECK,
                'No workflow blueprints are registered in this host, so there is nothing to check. '
                .'Blueprints stored as definition versions are per-tenant and out of scope here.',
            )];
        }

        $offenders = [];

        foreach ($names as $name) {
            if (! $this->registry->has($name)) {
                continue;
            }

            foreach ($this->reasons($this->registry->get($name)) as $reason) {
                $offenders[] = "{$name}: {$reason}";
            }
        }

        if ($offenders === []) {
            return [Finding::pass(
                self::CHECK,
                count($names).' registered blueprint(s) produce single-place markings, which a lifecycle can persist.',
            )];
        }

        return [Finding::warn(
            self::CHECK,
            'These registered blueprints declare multi-token markings that a lifecycle cannot persist '
            .'onto a scalar status attribute — '.implode('; ', $offenders).'. LifecycleService refuses '
            .'such a transition with a blocker rather than truncating it, so the move will fail at run '
            .'time. That is correct for a blueprint destined for the Control-seam node (the host owns '
            .'persistence there); it is a defect for one bound to a model lifecycle.',
        )];
    }

    /**
     * Every reason this blueprint's marking could exceed one place.
     *
     * @return list<string>
     */
    protected function reasons(WorkflowBlueprint $blueprint): array
    {
        $reasons = [];

        if (count($blueprint->initialMarking) > 1) {
            $reasons[] = 'initial marking holds '.count($blueprint->initialMarking)
                .' places ['.implode(', ', $blueprint->initialMarking).']';
        }

        foreach ($blueprint->transitions as $transition) {
            if (count($transition->to) > 1) {
                $reasons[] = "transition [{$transition->name}] produces ".count($transition->to)
                    .' places ['.implode(', ', $transition->to).']';
            }
        }

        return $reasons;
    }
}
