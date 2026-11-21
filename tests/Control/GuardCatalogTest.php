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

// ---- agreements 11b: a guard's own params validator, run at save ------------------------------------------------------

function paramsBlueprint(string $guard, array $params): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'x',
        'places' => ['a', 'b'],
        'transitions' => [['name' => 't', 'from' => 'a', 'to' => 'b', 'guard' => $guard, 'metadata' => ['guardParams' => $params]]],
    ]);
}

it('rejects a definition whose guard params its guard\'s own validator refuses, and accepts valid ones', function () {
    $known = ['terms'];
    $guards = new GuardRegistry;
    $guards->register('names_a_thing', fn () => true, validateParams: function (array $params) use (&$known): array {
        return in_array($params['thing'] ?? null, $known, true) ? [] : ['thing ['.($params['thing'] ?? '')."] isn't registered."];
    });
    $validator = new BlueprintValidator($guards, new TransitionEffectRegistry);

    expect(fn () => $validator->validate(paramsBlueprint('names_a_thing', ['thing' => 'nope'])))
        ->toThrow(InvalidArgumentException::class, "Transition [t] has guard [names_a_thing] params that aren't valid: thing [nope] isn't registered.");
    expect($validator->errors(paramsBlueprint('names_a_thing', ['thing' => 'terms'])))->toBe([]);

    // The validator reads LIVE state: registering the thing later makes the same params valid.
    $known[] = 'nope';
    expect($validator->errors(paramsBlueprint('names_a_thing', ['thing' => 'nope'])))->toBe([]);
});

it('keeps a guard with no validator to membership only, whatever its params, and re-registering replaces the validator', function () {
    $guards = new GuardRegistry;
    $guards->register('plain', fn () => true);
    $validator = new BlueprintValidator($guards, new TransitionEffectRegistry);

    expect($validator->errors(paramsBlueprint('plain', ['anything' => 'at all'])))->toBe([])
        ->and($guards->paramsErrors('plain', ['anything' => 'at all']))->toBe([])
        ->and($guards->paramsErrors('ghost', []))->toBe([]);

    $guards->register('plain', fn () => true, validateParams: fn (array $p): array => ['always refused']);
    expect($validator->errors(paramsBlueprint('plain', [])))->toHaveCount(1);

    $guards->register('plain', fn () => true);
    expect($validator->errors(paramsBlueprint('plain', [])))->toBe([]);
});

it('never puts the validator on the catalog (the catalog crosses the wire)', function () {
    $guards = new GuardRegistry;
    $guards->register('names_a_thing', fn () => true, label: 'Names a thing', validateParams: fn (array $p): array => []);

    expect($guards->catalogEntry('names_a_thing'))->toBe(['name' => 'names_a_thing', 'label' => 'Names a thing', 'paramsSchema' => []]);
});
