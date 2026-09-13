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
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Exception\UndefinedTransitionException;
use Symfony\Component\Workflow\Workflow;

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
        bool $emitStatus = true,
    ): TransitionResult {
        $definition = $this->builder->build($blueprint);
        $dispatcher = new EventDispatcher;

        $this->wireGuards($dispatcher, $blueprint);

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

        // Announce computes enabled transitions after mutation. This runner has no announce
        // subscribers; its display projection performs that computation inside its own boundary.
        $workflow->apply($subject, $event, [Workflow::DISABLE_ANNOUNCE_EVENT => true]);
        if ($emitStatus) {
            $this->emitStatus($blueprint, $subject, $event, $statusSubject, $context, $markingProperty);
        }

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
        $dispatcher->addListener("workflow.{$blueprint->name}.guard", function (GuardEvent $event) {
            // Names can be shared by transitions from different places. The guard belongs to
            // this exact Transition object, as recorded by DefinitionBuilder.
            $ref = $event->getMetadata('guard', $event->getTransition());
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

    /**
     * Project an applied marking onto Display. Persistence-owning callers may defer this until
     * their transaction commits. Computing enabled actions is also display work here: a broken
     * next-step guard must not turn a completed transition into a failed execution.
     */
    public function emitStatus(
        WorkflowBlueprint $blueprint,
        object $markingSubject,
        string $transition,
        ?Model $statusSubject = null,
        ?TransitionContext $context = null,
        string $markingProperty = 'marking',
    ): void {
        try {
            $places = $this->places($markingSubject, $markingProperty);
            $terminal = $this->enabled($blueprint, $markingSubject, $markingProperty) === [];
            $state = $terminal ? State::Complete : State::Running;
            $context ??= new TransitionContext;

            $this->emitter->emit(
                $statusSubject,
                StatusEvent::whole($state, "transition:{$transition} → ".implode(',', $places)),
                $context->runId,
                $context->actor,
            );
        } catch (\Throwable $e) {
            report($e);
        }
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
