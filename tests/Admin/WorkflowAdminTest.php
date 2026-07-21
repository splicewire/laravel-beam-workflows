<?php

use Splicewire\Beam\Workflows\Admin\Contracts\GovernableTypeSource;
use Splicewire\Beam\Workflows\Admin\WorkflowAdmin;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;

/*
 * The seam pass — the model-agnostic workflow-admin behaviour lives in the package. A host wires a
 * thin controller over this; transport/auth/persistence/coverage stay host-side.
 */

function adminBlueprint(?string $guard = null): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'composition.lifecycle',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => $guard]],
    ]);
}

it('assembles the catalog: schema + guards + governable types (registered, host-supplied, bound)', function () {
    app(GuardRegistry::class)->register('known', fn () => true, label: 'Known');
    app(WorkflowTypeRegistry::class)->register('composition', 'Composition');
    app(WorkflowBindingRegistry::class)->bind('legacy-type', 'composition.lifecycle');

    $source = new class implements GovernableTypeSource
    {
        public function governableTypes(): array
        {
            return [['key' => 'article', 'label' => 'Schema · article']];
        }
    };

    $catalog = app(WorkflowAdmin::class)->catalog($source);

    expect($catalog['blueprintSchema']['title'])->toBe('WorkflowBlueprint')
        ->and(collect($catalog['guards'])->pluck('name'))->toContain('known')
        ->and(collect($catalog['types'])->pluck('key'))
        // registered + host-supplied + bound-but-unregistered, none dropped.
        ->toContain('composition', 'article', 'legacy-type');
});

it('lists lineages with versions + bound types, and reads one', function () {
    $store = app(DefinitionStore::class);
    $store->createLineage('composition.lifecycle', 'Composition Lifecycle', adminBlueprint());
    app(WorkflowBindingRegistry::class)->bind('composition', 'composition.lifecycle');

    $admin = app(WorkflowAdmin::class);
    $lineages = $admin->lineages();

    expect($lineages)->toHaveCount(1)
        ->and($lineages[0]['key'])->toBe('composition.lifecycle')
        ->and($lineages[0]['boundTypes'])->toBe(['composition'])
        ->and($lineages[0]['versions'][0]['version'])->toBe(1)
        ->and($lineages[0]['versions'][0]['isActive'])->toBeTrue()
        ->and($admin->lineage('composition.lifecycle')['name'])->toBe('Composition Lifecycle')
        ->and($admin->lineage('missing'))->toBeNull();
});

it('validates then forks a version; rejects an unknown guard', function () {
    $store = app(DefinitionStore::class);
    $store->createLineage('composition.lifecycle', 'Composition Lifecycle', adminBlueprint());
    app(GuardRegistry::class)->register('known', fn () => true);
    $admin = app(WorkflowAdmin::class);

    // A valid edit forks v2.
    $v2 = $admin->saveVersion('composition.lifecycle', adminBlueprint('known')->toArray());
    expect($v2['version'])->toBe(2)->and($v2['isActive'])->toBeTrue();

    // An unknown guard is rejected before anything is written.
    expect(fn () => $admin->saveVersion('composition.lifecycle', adminBlueprint('ghost')->toArray()))
        ->toThrow(InvalidArgumentException::class, 'not in the guard catalog');
});
