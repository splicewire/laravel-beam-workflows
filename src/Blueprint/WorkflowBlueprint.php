<?php

namespace Splicewire\Beam\Workflows\Blueprint;

use InvalidArgumentException;
use Splicewire\Beam\Workflows\Bridge\DefinitionBuilder;

/**
 * A `symfony/workflow` definition described as DATA (ticket 08) — the prerequisite for any
 * editability. A blueprint is a first-class, validatable, storable artifact (an array today, a
 * form/editor surface tomorrow) that the {@see DefinitionBuilder}
 * turns into a real `Definition`. Both the Seam B node and the composition lifecycle consume a
 * blueprint-built definition rather than hand-writing PHP `Transition` objects.
 *
 * Load-bearing distinction (see the feature apocryphon): a blueprint *defines* one workflow; a
 * Circuit *composes* workflows (a workflow is a node in a Circuit). A state machine is a
 * multi-place workflow-net, not a DAG, and is not drawn on the Circuit canvas. This class makes
 * definitions data-shaped; it ships NO editor UI — it unblocks one.
 */
readonly class WorkflowBlueprint
{
    /**
     * @param  list<string>  $places
     * @param  list<string>  $initialMarking
     * @param  list<TransitionBlueprint>  $transitions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public array $places,
        public array $initialMarking,
        public array $transitions,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['name'], $data['places'], $data['transitions'])) {
            throw new InvalidArgumentException('A workflow blueprint requires name, places and transitions.');
        }

        $places = array_values(array_map('strval', $data['places']));

        // The initial marking defaults to the first place (a single-place start) when unspecified.
        $initial = isset($data['initial'])
            ? array_values(array_map('strval', (array) $data['initial']))
            : [$places[0] ?? throw new InvalidArgumentException('A blueprint needs at least one place.')];

        $transitions = array_map(
            fn ($t) => $t instanceof TransitionBlueprint ? $t : TransitionBlueprint::fromArray($t),
            $data['transitions'],
        );

        $blueprint = new self(
            name: (string) $data['name'],
            places: $places,
            initialMarking: $initial,
            transitions: array_values($transitions),
            metadata: $data['metadata'] ?? [],
        );

        $blueprint->assertReferentialIntegrity();

        return $blueprint;
    }

    /**
     * Every transition's `from`/`to` and every initial place must name a declared place — an
     * unknown place is a blueprint authoring error, caught here rather than surfacing as an opaque
     * symfony/workflow failure deep in a run.
     */
    public function assertReferentialIntegrity(): void
    {
        $known = array_flip($this->places);

        foreach ($this->initialMarking as $place) {
            if (! isset($known[$place])) {
                throw new InvalidArgumentException("Initial marking references undeclared place [{$place}].");
            }
        }

        foreach ($this->transitions as $t) {
            foreach ([...$t->from, ...$t->to] as $place) {
                if (! isset($known[$place])) {
                    throw new InvalidArgumentException("Transition [{$t->name}] references undeclared place [{$place}].");
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'places' => $this->places,
            'initial' => $this->initialMarking,
            'transitions' => array_map(fn (TransitionBlueprint $t) => $t->toArray(), $this->transitions),
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * The JSON Schema a blueprint validates against — so a stored/edited blueprint is checkable
     * before it is built, and a future editor surface has a contract to render.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $placeName = ['type' => 'string', 'minLength' => 1];
        $places = ['oneOf' => [$placeName, ['type' => 'array', 'items' => $placeName, 'minItems' => 1]]];

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'WorkflowBlueprint',
            'type' => 'object',
            'required' => ['name', 'places', 'transitions'],
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
                'places' => ['type' => 'array', 'items' => $placeName, 'minItems' => 1],
                'initial' => $places,
                'transitions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['name', 'from', 'to'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1],
                            'from' => $places,
                            'to' => $places,
                            'guard' => ['type' => 'string'],
                            'metadata' => ['type' => 'object'],
                        ],
                    ],
                ],
                'metadata' => ['type' => 'object'],
            ],
        ];
    }
}
