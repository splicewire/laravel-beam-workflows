<?php

use Rushing\Popcorn\Registries\Nested;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;

/**
 * registry-kernel 38 — this package's five rows conform to the `Registry` contract.
 *
 * The conformance test ticket 58 said was preserved alongside the migration patch. It was not: the
 * patch holds six `src/` files and no test at all, so the claim was stale and this is written fresh.
 *
 * These assert the CONTRACT, not the domain behaviour each registry's own suite already covers. The
 * failure they exist to catch is the one the gate names — a class that declares `#[IsRegistry]` and
 * carries the root, but never implements the interface, so nothing can enumerate or route it.
 */
dataset('rows', [
    'blueprints' => [WorkflowRegistry::class, 'beam.workflows.blueprints'],
    'guards' => [GuardRegistry::class, 'beam.workflows.guards'],
    'subject-resolvers' => [SubjectResolverRegistry::class, 'beam.workflows.subject-resolvers'],
    'effects' => [TransitionEffectRegistry::class, 'beam.workflows.effects'],
    'bindings' => [WorkflowBindingRegistry::class, 'beam.workflows.bindings'],
]);

it('implements the Registry contract', function (string $class) {
    expect(new ReflectionClass($class))->toBeInstanceOf(ReflectionClass::class)
        ->and(is_subclass_of($class, Registry::class))->toBeTrue();
})->with('rows');

it('declares the root the gate expects', function (string $class, string $root) {
    $attributes = (new ReflectionClass($class))->getAttributes(\Rushing\Popcorn\Registries\IsRegistry::class);

    expect($attributes)->not->toBeEmpty()
        ->and($attributes[0]->newInstance()->root)->toBe($root);
})->with('rows');

/**
 * ⚠️ Recipe amendment 4 from pass 1 — "`keys()` is the silent collision nobody had hit." A port
 * returning bare relative strings is signature-compatible with the contract's absolute
 * `RegistryKey`s: it compiles, the suite passes, and the index reads the wrong thing. So this
 * asserts the TYPE, which is the only thing that distinguishes the two.
 */
it('returns RegistryKey objects from keys(), never bare strings', function (string $class, string $root) {
    $registry = app($class);

    // ⚠️ An empty registry makes the loop below assert NOTHING, and pest marks the case risky rather
    // than failing it — so the first version of this test reported five risky cases and would have
    // read as coverage. Assert the shape unconditionally, then the element type where there is one.
    expect($registry->keys())->toBeArray();

    foreach ($registry->keys() as $key) {
        expect($key)->toBeInstanceOf(RegistryKey::class);
    }
})->with('rows');

it('stamps its declared root onto a registered key', function () {
    $registry = app(WorkflowRegistry::class);
    $registry->register('demo-flow', \Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint::fromArray([
        'name' => 'demo-flow',
        'places' => ['draft', 'live'],
        'transitions' => [],
    ]));

    expect((string) $registry->keys()[0])->toStartWith('beam.workflows.blueprints.');
});

/**
 * The row 58 D5 disposed of. A host type key is spelled `acme/press-release`, and `/` is not a `Key`
 * character — the guard the migration patch shipped used `Key::tryParse()` and therefore answered
 * FALSE for every real binding, making the registry unaddressable while dotted fixtures passed.
 */
it('addresses a slash-spelled host type key, which plain Key cannot express', function () {
    expect(\Rushing\Popcorn\Registries\Key::tryParse('acme/press-release'))->toBeNull()
        ->and(RelativeUriKey::tryParse('acme/press-release'))->not->toBeNull()
        ->and(RelativeUriKey::parse('acme/press-release')->segments())->toBe(['acme', 'press-release']);
});

it('keeps the slash translation lossless in both directions', function () {
    expect((string) RelativeUriKey::parse('acme/press-release'))->toBe('acme/press-release');
});
