<?php

use Splicewire\Beam\Workflows\Blueprint\BlueprintValidator;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\MarkingSubject;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;

/*
 * Ticket 05 — the guard catalog (the code-only security line). A registered guard advertises a
 * catalog entry; a blueprint referencing an unknown guard is rejected at validation; a catalog param
 * drives a guard's decision. Nothing here lets data introduce guard LOGIC — only a name + params.
 */

it('advertises a catalog entry (name, label, params schema) per registered guard', function () {
    $guards = new GuardRegistry;
    $guards->register('no_stale_cells', fn () => true, label: 'No stale cells');
    $guards->register('review_not_required', fn () => true, paramsSchema: [
        'type' => 'object',
        'properties' => ['require_review' => ['type' => 'boolean']],
    ]);

    $catalog = collect($guards->guardCatalog())->keyBy('name');

    expect($catalog['no_stale_cells']['label'])->toBe('No stale cells')
        ->and($catalog['no_stale_cells']['paramsSchema'])->toBe([])
        // label defaults to a humanised ref when omitted.
        ->and($catalog['review_not_required']['label'])->toBe('Review Not Required')
        ->and($catalog['review_not_required']['paramsSchema']['properties'])->toHaveKey('require_review');
});

it('rejects a blueprint whose transition references an unknown guard', function () {
    $guards = new GuardRegistry;
    $guards->register('known_guard', fn () => true);
    $validator = new BlueprintValidator($guards, new TransitionEffectRegistry);

    $blueprint = WorkflowBlueprint::fromArray([
        'name' => 'x',
        'places' => ['a', 'b'],
        'transitions' => [['name' => 't', 'from' => 'a', 'to' => 'b', 'guard' => 'ghost_guard']],
    ]);

    expect(fn () => $validator->validate($blueprint))
        ->toThrow(InvalidArgumentException::class, 'not in the guard catalog');

    expect($validator->errors($blueprint))->toHaveCount(1);
});

it('accepts a blueprint whose guards are all catalog members', function () {
    $guards = new GuardRegistry;
    $guards->register('known_guard', fn () => true);
    $validator = new BlueprintValidator($guards, new TransitionEffectRegistry);

    $blueprint = WorkflowBlueprint::fromArray([
        'name' => 'x',
        'places' => ['a', 'b'],
        'transitions' => [['name' => 't', 'from' => 'a', 'to' => 'b', 'guard' => 'known_guard']],
    ]);

    expect($validator->errors($blueprint))->toBe([]);
});

it('lets a catalog param drive a guard decision (params → context → closure)', function () {
    $guards = new GuardRegistry;
    $guards->register(
        'review_not_required',
        fn (object $subject): bool|string => ($subject->context['require_review'] ?? false)
            ? 'Review is required before publishing.'
            : true,
        paramsSchema: ['type' => 'object', 'properties' => ['require_review' => ['type' => 'boolean']]],
    );

    // The author-set param arrives in the subject context (the v1 mechanism, unchanged).
    $blocked = MarkingSubject::fromPlaces(['draft'], ['require_review' => true]);
    $allowed = MarkingSubject::fromPlaces(['draft'], ['require_review' => false]);

    expect(($guards->get('review_not_required'))($blocked))->toBe('Review is required before publishing.')
        ->and(($guards->get('review_not_required'))($allowed))->toBeTrue();
});
