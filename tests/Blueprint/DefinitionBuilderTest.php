<?php

use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Bridge\DefinitionBuilder;
use Splicewire\Beam\Workflows\Bridge\WorkflowFactory;
use Symfony\Component\Workflow\Definition;

function compositionBlueprintArray(): array
{
    return [
        'name' => 'composition.lifecycle',
        'places' => ['draft', 'review', 'published'],
        'initial' => ['draft'],
        'transitions' => [
            ['name' => 'submit_for_review', 'from' => 'draft', 'to' => 'review', 'guard' => 'no_stale_cells'],
            ['name' => 'publish', 'from' => 'review', 'to' => 'published'],
            ['name' => 'unpublish', 'from' => 'published', 'to' => 'draft'],
        ],
    ];
}

it('builds a symfony/workflow definition from a data blueprint', function () {
    $definition = app(DefinitionBuilder::class)->build(compositionBlueprintArray());

    expect($definition)->toBeInstanceOf(Definition::class)
        ->and($definition->getPlaces())->toHaveKeys(['draft', 'review', 'published'])
        ->and($definition->getInitialPlaces())->toBe(['draft'])
        ->and(collect($definition->getTransitions())->map->getName()->all())
        ->toBe(['submit_for_review', 'publish', 'unpublish']);
});

it('carries a guard reference into the transition metadata', function () {
    $definition = app(DefinitionBuilder::class)->build(compositionBlueprintArray());

    $submit = collect($definition->getTransitions())->firstWhere(fn ($t) => $t->getName() === 'submit_for_review');

    expect($definition->getMetadataStore()->getTransitionMetadata($submit))
        ->toBe(['guard' => 'no_stale_cells']);
});

it('round-trips: a blueprint drives observed transitions that match the blueprint', function () {
    $blueprint = WorkflowBlueprint::fromArray(compositionBlueprintArray());
    $definition = app(DefinitionBuilder::class)->build($blueprint);
    $workflow = app(WorkflowFactory::class)->make($definition, $blueprint->name);

    $subject = new class
    {
        public ?array $marking = null;
    };

    // Enabled transitions from the initial marking match exactly what the blueprint declared out of `draft`.
    expect(collect($workflow->getEnabledTransitions($subject))->map->getName()->all())
        ->toBe(['submit_for_review']);

    $workflow->apply($subject, 'submit_for_review');
    expect($workflow->getMarking($subject)->getPlaces())->toHaveKey('review');

    $workflow->apply($subject, 'publish');
    expect($workflow->getMarking($subject)->getPlaces())->toHaveKey('published');

    $workflow->apply($subject, 'unpublish');
    expect($workflow->getMarking($subject)->getPlaces())->toHaveKey('draft');
});

it('supports workflow-net multi-place transitions from a blueprint', function () {
    $definition = app(DefinitionBuilder::class)->build([
        'name' => 'fork.join',
        'places' => ['start', 'a', 'b', 'done'],
        'initial' => ['start'],
        'transitions' => [
            ['name' => 'fork', 'from' => 'start', 'to' => ['a', 'b']],
            ['name' => 'join', 'from' => ['a', 'b'], 'to' => 'done'],
        ],
    ]);

    $workflow = app(WorkflowFactory::class)->make($definition, 'fork.join');
    $subject = new class
    {
        public ?array $marking = null;
    };

    $workflow->apply($subject, 'fork');

    // A token now occupies BOTH places at once — a list marking, not a scalar.
    expect($workflow->getMarking($subject)->getPlaces())->toHaveKeys(['a', 'b']);

    $workflow->apply($subject, 'join');
    expect($workflow->getMarking($subject)->getPlaces())->toBe(['done' => 1]);
});

it('rejects a blueprint that references an undeclared place', function () {
    expect(fn () => WorkflowBlueprint::fromArray([
        'name' => 'broken',
        'places' => ['draft', 'published'],
        'transitions' => [
            ['name' => 'publish', 'from' => 'draft', 'to' => 'nowhere'],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'undeclared place [nowhere]');
});

it('round-trips a blueprint through array form losslessly', function () {
    $blueprint = WorkflowBlueprint::fromArray(compositionBlueprintArray());
    $again = WorkflowBlueprint::fromArray($blueprint->toArray());

    expect($again->toArray())->toBe($blueprint->toArray())
        ->and($blueprint->places)->toBe(['draft', 'review', 'published']);
});

it('exposes a json schema the blueprint validates against', function () {
    $schema = WorkflowBlueprint::jsonSchema();

    expect($schema['required'])->toBe(['name', 'places', 'transitions'])
        ->and($schema['properties']['transitions']['items']['required'])->toBe(['name', 'from', 'to']);
});
