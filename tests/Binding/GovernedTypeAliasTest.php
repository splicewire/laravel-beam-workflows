<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Splicewire\Beam\Workflows\Awaiting\EloquentAwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;
use Splicewire\Beam\Workflows\Binding\UnaliasedGovernedType;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Data\WorkflowAwaitingRowData;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/*
 * Ticket 05 ruling 1 (the integrator, 2026-10-04): the Workflow Queue's Subject Type column never shows a PHP class-string
 * (laravel-frame ADR-0004). It shows `subject_type`, which is the subject's getMorphClass(): the morph alias when the class
 * has one, its FQCN when it has none. So a governed type MUST have an alias, and a class without one fails loudly the first
 * time it resolves as governed, which is before any awaiting row can be stamped. ("Bind time" in the ruling: there is no
 * class-level bind step; a class meets its workflow type when WorkflowBindingRegistry::forObject() finds its binding.)
 */

class UnaliasedGovernedPost extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $guarded = [];

    public function workflowType(): string
    {
        return 'governed-post';
    }
}

class AliasedGovernedPost extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $guarded = [];

    public function workflowType(): string
    {
        return 'aliased-post';
    }
}

beforeEach(function () {
    Relation::morphMap(['aliased_post' => AliasedGovernedPost::class]);
});

it('shows an aliased governed type by its alias on the Workflow Queue', function () {
    app(WorkflowBindingRegistry::class)->bind('aliased-post', 'publish_flow');
    $post = (new AliasedGovernedPost)->forceFill(['id' => 'post-1']);

    expect(app(WorkflowBindingRegistry::class)->forObject($post, app(TypeIdentityResolver::class)))->not->toBeNull();
    app(EloquentAwaitingStore::class)->stamp($post, 'review', 'role:editor');

    expect(WorkflowAwaitingRowData::from(WorkflowAwaiting::query()->sole())->subject_type)->toBe('aliased_post');
});

it('refuses a governed type without a morph alias the first time it resolves as governed, naming the class and the fix', function () {
    app(WorkflowBindingRegistry::class)->bind('governed-post', 'publish_flow');

    expect(fn () => app(WorkflowBindingRegistry::class)->forObject(new UnaliasedGovernedPost, app(TypeIdentityResolver::class)))
        ->toThrow(UnaliasedGovernedType::class, UnaliasedGovernedPost::class)
        ->and(fn () => app(WorkflowBindingRegistry::class)->forObject(new UnaliasedGovernedPost, app(TypeIdentityResolver::class)))
        ->toThrow(UnaliasedGovernedType::class, "Relation::morphMap(['unaliased_governed_post' => ".UnaliasedGovernedPost::class.'::class])');
});

it('leaves an unaliased model alone while its type is not governed', function () {
    expect(app(WorkflowBindingRegistry::class)->forObject(new UnaliasedGovernedPost, app(TypeIdentityResolver::class)))->toBeNull();
});
