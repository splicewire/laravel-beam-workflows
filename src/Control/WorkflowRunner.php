<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Bridge\DefinitionBuilder;
use Splicewire\Beam\Workflows\Bridge\WorkflowFactory;
use Splicewire\Beam\Workflows\Display\State;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Exception\UndefinedTransitionException;

/**
 * The heart of the Control seam: apply ONE transition to a subject, honoring the blueprint's
 * guards and emitting a Display {@see StatusEvent} for every transition that actually applies.
 *
 * Reused by both consumers so the Control logic lives in exactly one place:
 *   - the Seam B node ({@see WorkflowApplyInvocable}) — subject is a throwaway {@see MarkingSubject}
 *     hydrated from the port; persistence is the host's job;
 *   - the Seam C composition lifecycle — subject is the Composition model itself.
 *
 * The split from NOTES.md is enforced here: symfony/workflow is **Control** (it decides whether a
 * transition is legal — `can()` + guards — and mutates the marking authoritatively); the emitted
 * StatusEvent is **Display** (fire-and-forget, lossy-OK). A guard failure rejects the transition
 * WITHOUT applying and surfaces the reason; it never half-mutates.
 */
class WorkflowRunner
{
    public function __construct(
        protected DefinitionBuilder $builder,
        protected WorkflowFactory $factory,
        protected StatusEmitter $emitter,
        protected GuardRegistry $guards,
    ) {}

    /**
     * @param  object  $subject  Carries the marking on `$markingProperty` (a MarkingSubject for the
     *                           node, the host model for a lifecycle).
     * @param  Model|null  $statusSubject  Who the emitted StatusEvent is *about* (may be the host
     *                                     model even when the marking rides a throwaway subject).
     * @param  TransitionContext|null  $context  The run id + opaque actor token carried onto the emitted
     *                                           Display event (both null for an ungrouped, actorless run).
     */
    public function apply(
        WorkflowBlueprint $blueprint,
        object $subject,
        string $event,
        ?Model $statusSubject = null,
        ?TransitionContext $context = null,
        string $markingProperty = 'marking',
    ): TransitionResult {
        $definition = $this->builder->build($blueprint);
        $dispatcher = new EventDispatcher;

        $this->wireGuards($dispatcher, $blueprint);
        $this->wireStatusEmission($dispatcher, $blueprint, $statusSubject, $context ?? new TransitionContext);

        $workflow = $this->factory->make($definition, $blueprint->name, $markingProperty, $dispatcher);

        // CONTROL, authoritative: is the move legal? An unknown transition name, a marking that
        // does not enable it, or a vetoing guard all land here as "cannot" — never a half-apply.
        //
        // BOTH calls are inside the try, and that is load-bearing rather than defensive. symfony's
        // `can()` returns false for an undefined transition without raising, so the
        // UndefinedTransitionException actually surfaces from `buildTransitionBlockerList()` in the
        // rejection branch below — which, wrapped only around `can()`, escaped this method and made
        // an unknown transition name throw at the caller instead of returning the "cannot" result
        // this comment promises.
        try {
            $can = $workflow->can($subject, $event);

            if (! $can) {
                $blockers = [];
                foreach ($workflow->buildTransitionBlockerList($subject, $event) as $blocker) {
                    $blockers[] = $blocker->getMessage();
                }

                return new TransitionResult(
                    marking: $this->places($subject, $markingProperty),
                    transition: $event,
                    applied: false,
                    blockers: $blockers ?: ["Transition [{$event}] is not enabled."],
                );
            }
        } catch (UndefinedTransitionException $e) {
            return new TransitionResult(
                marking: $this->places($subject, $markingProperty),
                transition: $event,
                applied: false,
                blockers: ["Unknown transition [{$event}] for workflow [{$blueprint->name}]."],
            );
        }

        // The completed listener (wired above) emits the StatusEvent as a side effect of applying.
        $workflow->apply($subject, $event);

        return new TransitionResult(
            marking: $this->places($subject, $markingProperty),
            transition: $event,
            applied: true,
        );
    }

    /**
     * The transitions ENABLED for a subject right now — the marking allows them AND their guards do
     * not veto (symfony's `getEnabledTransitions` runs the guard listeners). This drives the
     * stepper's action buttons (ticket 07) so the UI offers exactly the legal moves, never a
     * hardcoded list. Returns transition names (a name may legally appear once).
     *
     * @return list<string>
     */
    public function enabled(
        WorkflowBlueprint $blueprint,
        object $subject,
        string $markingProperty = 'marking',
    ): array {
        $definition = $this->builder->build($blueprint);
        $dispatcher = new EventDispatcher;
        $this->wireGuards($dispatcher, $blueprint);

        $workflow = $this->factory->make($definition, $blueprint->name, $markingProperty, $dispatcher);

        $names = [];
        foreach ($workflow->getEnabledTransitions($subject) as $transition) {
            $names[] = $transition->getName();
        }

        return array_values(array_unique($names));
    }

    protected function wireGuards(EventDispatcher $dispatcher, WorkflowBlueprint $blueprint): void
    {
        $guardMap = [];
        foreach ($blueprint->transitions as $t) {
            if ($t->guard !== null) {
                $guardMap[$t->name] = $t->guard;
            }
        }

        if ($guardMap === []) {
            return;
        }

        $dispatcher->addListener("workflow.{$blueprint->name}.guard", function (GuardEvent $event) use ($guardMap) {
            $ref = $guardMap[$event->getTransition()->getName()] ?? null;
            if ($ref === null) {
                return;
            }

            // A declared-but-unregistered guard is a misconfiguration — fail CLOSED (block), never
            // silently let the guarded move through.
            if (! $this->guards->has($ref)) {
                $event->setBlocked(true, "Guard [{$ref}] is not registered.");

                return;
            }

            $verdict = ($this->guards->get($ref))($event->getSubject());
            if ($verdict !== true) {
                $event->setBlocked(true, is_string($verdict) ? $verdict : "Blocked by guard [{$ref}].");
            }
        });
    }

    protected function wireStatusEmission(EventDispatcher $dispatcher, WorkflowBlueprint $blueprint, ?Model $statusSubject, TransitionContext $context): void
    {
        $dispatcher->addListener("workflow.{$blueprint->name}.completed", function (CompletedEvent $event) use ($statusSubject, $context) {
            $transition = $event->getTransition()?->getName() ?? '';
            $places = array_keys($event->getMarking()->getPlaces());

            // Reaching a sink place (no further enabled transitions) is a Complete; otherwise the
            // process is still Running. This automatic mapping means a lifecycle need not annotate
            // every place with a state.
            $terminal = $event->getWorkflow()->getEnabledTransitions($event->getSubject()) === [];
            $state = $terminal ? State::Complete : State::Running;

            // ISOLATED, and that is the whole Display-vs-Control invariant in one place. This
            // listener runs INSIDE `Workflow::apply()`, on the far side of the marking mutation, so
            // an emit that raised — a downed timeline, a full disk, a swapped-out activity model —
            // propagated straight out of `apply()` and turned a legal, already-applied transition
            // into an exception at the caller. Display is derived, eventually-consistent and
            // lossy-OK; a status projection must never be the thing that breaks execution
            // correctness. Report it and let the run continue.
            try {
                $this->emitter->emit(
                    $statusSubject,
                    StatusEvent::whole($state, "transition:{$transition} → ".implode(',', $places)),
                    $context->runId,
                    $context->actor,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * @return list<string>
     */
    protected function places(object $subject, string $markingProperty): array
    {
        $marking = $subject->{$markingProperty} ?? [];

        return array_values(array_keys(is_array($marking) ? $marking : []));
    }
}
