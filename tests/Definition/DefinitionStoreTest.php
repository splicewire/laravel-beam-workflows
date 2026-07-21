<?php

use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Definition\WorkflowDefinitionVersion;

/*
 * Ticket 03 — the load-bearing invariant. A definition is immutable once written; editing forks a
 * new version; an object stays pinned to the version it started on until explicitly migrated. These
 * tests prove the store enforces that (never a footgun for the future in-app editor).
 */

function draftBlueprint(array $extraPlaces = [], array $extraTransitions = []): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'composition.lifecycle',
        'places' => array_merge(['draft', 'published'], $extraPlaces),
        'initial' => ['draft'],
        'transitions' => array_merge([
            ['name' => 'publish', 'from' => 'draft', 'to' => 'published'],
        ], $extraTransitions),
    ]);
}

it('creates a lineage with an active version 1', function () {
    $store = app(DefinitionStore::class);

    $lineage = $store->createLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());

    expect($lineage->versions()->count())->toBe(1)
        ->and($lineage->activeVersion()->version)->toBe(1)
        ->and($lineage->activeVersion()->is_active)->toBeTrue()
        ->and($store->activeBlueprint('composition.lifecycle')->name)->toBe('composition.lifecycle');
});

it('forks a new immutable version and moves the active pointer', function () {
    $store = app(DefinitionStore::class);
    $store->createLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());

    // Edit = fork: add a `review` place + transition.
    $v2 = $store->fork('composition.lifecycle', draftBlueprint(
        extraPlaces: ['review'],
        extraTransitions: [['name' => 'submit', 'from' => 'draft', 'to' => 'review']],
    ));

    expect($v2->version)->toBe(2)
        ->and($v2->is_active)->toBeTrue()
        ->and($store->activeVersion('composition.lifecycle')->version)->toBe(2)
        // Version 1 still exists, now inactive — history is preserved, not overwritten.
        ->and(WorkflowDefinitionVersion::query()->where('version', 1)->first()->is_active)->toBeFalse();
});

it('refuses to mutate a persisted version in place (immutability enforced by the model)', function () {
    $store = app(DefinitionStore::class);
    $lineage = $store->createLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());
    $version = $lineage->activeVersion();

    $version->blueprint = draftBlueprint(extraPlaces: ['sneaky'])->toArray();

    expect(fn () => $version->save())
        ->toThrow(RuntimeException::class, 'is immutable');
});

it('keeps an existing object pinned to its version when the active pointer bumps', function () {
    $store = app(DefinitionStore::class);
    $store->createLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());

    // An object started on v1: capture its pinned version id.
    $pinnedId = $store->activeVersion('composition.lifecycle')->id;

    // The definition is edited (v2 becomes active) AFTER the object was pinned.
    $store->fork('composition.lifecycle', draftBlueprint(extraPlaces: ['review']));

    // New objects resolve to v2; the pinned object still resolves to v1's frozen graph.
    expect($store->activeVersion('composition.lifecycle')->version)->toBe(2)
        ->and($store->version($pinnedId)->version)->toBe(1)
        ->and($store->version($pinnedId)->toBlueprint()->places)->not->toContain('review');
});

it('seeds a system default idempotently and never clobbers a tenant fork', function () {
    $store = app(DefinitionStore::class);

    $first = $store->ensureSystemLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());
    expect($first->is_system)->toBeTrue();

    // A tenant forks the system default (adds a place); active is now v2.
    $store->fork('composition.lifecycle', draftBlueprint(extraPlaces: ['review']));

    // Re-seeding (e.g. next boot) must NOT reset the lineage back to v1.
    $again = $store->ensureSystemLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());

    expect($again->is($first))->toBeTrue()
        ->and($store->activeVersion('composition.lifecycle')->version)->toBe(2);
});

it('can roll the active pointer back to an earlier version without forking', function () {
    $store = app(DefinitionStore::class);
    $store->createLineage('composition.lifecycle', 'Composition Lifecycle', draftBlueprint());
    $v1Id = $store->activeVersion('composition.lifecycle')->id;
    $store->fork('composition.lifecycle', draftBlueprint(extraPlaces: ['review']));

    $rolled = $store->activateVersion('composition.lifecycle', $v1Id);

    expect($rolled->version)->toBe(1)
        ->and($store->activeVersion('composition.lifecycle')->version)->toBe(1)
        ->and(WorkflowDefinitionVersion::query()->count())->toBe(2); // no history lost
});
