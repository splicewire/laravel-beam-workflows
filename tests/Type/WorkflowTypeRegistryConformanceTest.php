<?php

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;

/*
 * The archetype **a / `RunAll`** exemplar migration (registry-kernel ticket 37), and the other half of
 * the ticket's AXIS TEST: ticket 15 Q1 replaced the registrar-set × arity axis with a risk axis, on the
 * bet that `PickOne` and `RunAll` are one archetype differing by a single `arity:` argument. This file
 * and beam's `RealmRegistryConformanceTest` are the two sides of that bet.
 *
 * What makes this the RunAll exemplar rather than a second PickOne: the read that matters is the whole
 * enumeration behind an admin dropdown, so `keys()` ORDERING IS OBSERVABLE BY A USER. That ordering
 * used to be a PHP array's insertion order, trusted implicitly; it is now `Registry::keys()`'s
 * documented registration-order guarantee (08 D4).
 */

it('conforms to the kernel contract', function () {
    expect(app(WorkflowTypeRegistry::class))->toBeInstanceOf(Registry::class);
});

it('describes its root into the shared index, even while empty', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));

    $keys = array_map(strval(...), app(RegistryIndex::class)->keys());

    expect($keys)->toContain('beam.workflows.types');
    expect(app(WorkflowTypeRegistry::class)->keys())->toBe([]);
});

it('routes an absolute type key back to the registry', function () {
    app(WorkflowTypeRegistry::class)->register('composition', 'Composition');

    expect(app(RegistryIndex::class)->routeTo('beam.workflows.types.composition'))
        ->toBe(app(WorkflowTypeRegistry::class));
});

it('preserves registration order, which a user can see', function () {
    $types = app(WorkflowTypeRegistry::class);

    $types->register('song')->register('album', 'Long-Play Album')->register('composition');

    // The dropdown's option list — bare type keys out, in the order a host wrote them.
    expect($types->all())->toBe([
        ['key' => 'song', 'label' => 'Song'],
        ['key' => 'album', 'label' => 'Long-Play Album'],
        ['key' => 'composition', 'label' => 'Composition'],
    ]);

    // The keyspace's spelling of the same thing: relative in, absolute out (20 D2).
    expect(array_map(strval(...), $types->keys()))->toBe([
        'beam.workflows.types.song',
        'beam.workflows.types.album',
        'beam.workflows.types.composition',
    ]);
});

it('keeps the label-deriving convenience the port had before it conformed', function () {
    $types = app(WorkflowTypeRegistry::class)->register('composition');

    expect($types->label('composition'))->toBe('Composition')
        ->and($types->has('composition'))->toBeTrue()
        ->and($types->label('nope'))->toBeNull()
        ->and($types->has('nope'))->toBeFalse();
});

/*
 * The axis finding, asserted rather than narrated. A supersession does NOT grow the enumeration — which
 * is the property `RunAll` shares with `PickOne`, and the reason the two are one archetype: arity
 * governs which kernel READ the port's sugar delegates to, not how the class is migrated.
 */
it('supersedes a re-registered type instead of listing it twice', function () {
    $types = app(WorkflowTypeRegistry::class);

    $types->register('composition', 'Composition', by: 'beam-workflows');
    $types->register('composition', 'Composition (host)', by: 'the-host');

    expect($types->all())->toBe([['key' => 'composition', 'label' => 'Composition (host)']]);
});
