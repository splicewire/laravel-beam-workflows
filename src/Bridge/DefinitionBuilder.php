<?php

namespace Splicewire\Beam\Workflows\Bridge;

use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use SplObjectStorage;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition;

/**
 * Turns a data {@see WorkflowBlueprint} into a `symfony/workflow` {@see Definition} (ticket 08).
 *
 * This is the seam that makes definitions authorable as data instead of hand-written PHP: the node
 * (Seam B) and the composition lifecycle (Seam C) both build their Definition from a blueprint,
 * so there is one source of truth a future editor can read and write. Guard *references* travel
 * into the Definition's transition metadata (keyed by the Transition object), so downstream can
 * resolve `getMetadataStore()->getTransitionMetadata($t)['guard']` to an actual guard listener.
 */
class DefinitionBuilder
{
    /**
     * @param  WorkflowBlueprint|array<string, mixed>  $blueprint
     */
    public function build(WorkflowBlueprint|array $blueprint): Definition
    {
        $blueprint = $blueprint instanceof WorkflowBlueprint
            ? $blueprint
            : WorkflowBlueprint::fromArray($blueprint);

        $transitions = [];
        $transitionsMetadata = new SplObjectStorage;

        foreach ($blueprint->transitions as $t) {
            $transition = new Transition($t->name, $t->from, $t->to);
            $transitions[] = $transition;

            $metadata = $t->definitionMetadata();
            if ($metadata !== []) {
                $transitionsMetadata->attach($transition, $metadata);
            }
        }

        $metadataStore = new InMemoryMetadataStore(
            workflowMetadata: array_merge($blueprint->metadata, ['name' => $blueprint->name]),
            placesMetadata: [],
            transitionsMetadata: $transitionsMetadata,
        );

        return new Definition(
            places: $blueprint->places,
            transitions: $transitions,
            initialPlaces: $blueprint->initialMarking,
            metadataStore: $metadataStore,
        );
    }
}
