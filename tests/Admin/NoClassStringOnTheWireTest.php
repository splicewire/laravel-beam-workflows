<?php

use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Splicewire\Beam\Workflows\Admin\Contracts\GovernableTypeSource;
use Splicewire\Beam\Workflows\Admin\WorkflowAdmin;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;

/*
 * No PHP class-string reaches the workflows admin's wire (owner intent, laravel-frame ADR-0004; launch ticket 05 item 1).
 * The type keys a binding or a registered type may carry are registry keys, which cannot hold a `\`, so `boundTypes` and a
 * projection's `type` are clean by construction. A host's GovernableTypeSource is free-form, so the catalog must not
 * pass a class-string key or label through to the dropdown.
 */

it('refuses a class-string as a binding or a registered type key, so boundTypes and projection.type cannot carry one', function () {
    expect(fn () => app(WorkflowBindingRegistry::class)->bind('App\\Models\\Post', 'publish_flow'))->toThrow(InvalidRegistryKey::class)
        ->and(fn () => app(WorkflowTypeRegistry::class)->register('App\\Models\\Post', 'Post'))->toThrow(InvalidRegistryKey::class);
});

it('carries no class-string in the catalog or the lineages, even when a host type source offers one', function () {
    app(WorkflowTypeRegistry::class)->register('composition', 'Composition');
    // Guard and effect labels are free-form host text too (build.qa, review-r1).
    app(GuardRegistry::class)->register('require-review', fn () => true, label: 'App\\Guards\\RequireReview');
    app(TransitionEffectRegistry::class)->register('notify-owner', fn () => null, label: 'App\\Effects\\NotifyOwner');
    app(DefinitionStore::class)->createLineage('publish_flow', 'Publish flow', WorkflowBlueprint::fromArray([
        'name' => 'publish_flow', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('composition', 'publish_flow');

    $source = new class implements GovernableTypeSource
    {
        public function governableTypes(): array
        {
            return [
                ['key' => 'App\\Models\\Post', 'label' => 'Post'],
                ['key' => 'article', 'label' => 'App\\Models\\Article'],
            ];
        }
    };

    $admin = app(WorkflowAdmin::class);
    $catalog = $admin->catalog($source);
    $types = collect($catalog['types']);

    // The WHOLE payload the page receives, not only the types: blueprintSchema, guards and effects ride it too. The
    // pattern is class-shaped (a letter or digit, a JSON-escaped backslash, an uppercase letter), so a regex escape such
    // as \d inside blueprintSchema does not trip it (review-r1).
    $classShaped = '/[A-Za-z0-9]\\\\\\\\[A-Z]/';
    expect(json_encode($catalog))->not->toMatch($classShaped)
        ->and(json_encode($admin->lineages()))->not->toMatch($classShaped)
        ->and(collect($catalog['guards'])->firstWhere('name', 'require-review')['label'])->toBe('Require Review')
        ->and(collect($catalog['effects'])->firstWhere('name', 'notify-owner')['label'])->toBe('Notify Owner')
        // A class-string key cannot be bound, so offering it would only fail on save: it is left out.
        ->and($types->pluck('key')->all())->toBe(['composition', 'article'])
        // A class-string label reads as the class's own name.
        ->and($types->firstWhere('key', 'article')['label'])->toBe('Article');
});
