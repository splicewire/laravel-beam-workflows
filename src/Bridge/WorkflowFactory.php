<?php

namespace Splicewire\Beam\Workflows\Bridge;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Workflow;

/**
 * The thin Laravel <-> symfony/workflow bridge (ADR-0092 free-tier veneer).
 *
 * Its whole job is DI/config glue: turn a symfony {@see Definition} into a ready-to-run
 * {@see Workflow} whose marking lives on an in-memory subject property (never a DB store).
 * This is the load-bearing choice from the spike (`spike-symfony-workflow-as-node.md`): we use
 * symfony/workflow's *computation* (guards + transitions) and let the host own persistence, so
 * the same factory serves both the Circuit node (Seam B, marking rides the port envelope) and
 * the composition lifecycle (Seam C, marking projects onto a `CompositionStatus` column).
 *
 * Multi-place (workflow-net) support is on by default: the marking store is constructed with
 * `singleState: false`, so a marking is always a *list* of places — a token may occupy several
 * places at once. Callers that want a classic single-place state machine still read a one-element
 * list; nothing here forces a scalar.
 */
class WorkflowFactory
{
    /**
     * Build a Workflow over the given definition. The marking is read from / written to the
     * `$markingProperty` of whatever subject is passed to the workflow at call time (a throwaway
     * object for the node, or the host model for a lifecycle) — always as an array of places.
     *
     * The dispatcher is passed *per build*, not held on the factory: each state machine wires its
     * own guard/transition listeners (specific to that definition), so a shared app-level
     * dispatcher would leak listeners across unrelated definitions. Omit it for pure
     * computation (transitions with no guards/side-effects).
     */
    public function make(
        Definition $definition,
        string $name = 'workflow',
        string $markingProperty = 'marking',
        ?EventDispatcherInterface $dispatcher = null,
    ): Workflow {
        return new Workflow(
            $definition,
            new MethodMarkingStore(singleState: false, property: $markingProperty),
            $dispatcher,
            $name,
        );
    }
}
