<?php

use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Splicewire\Beam\Workflows\Binding\Binding;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;
use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/*
 * Ticket 02 — the binding seam. A bound type resolves to its Binding; an unbound type returns null
 * (⇒ unmanaged); pick-one arity means a re-bind replaces (never accumulates); params round-trip to
 * a guard through the Binding bag.
 */

class BindTestComposition implements WorkflowManaged
{
    use WorkflowManagedTrait;

    public function workflowType(): string
    {
        return 'composition';
    }
}

it('resolves a bound type to its binding, an unbound type to null', function () {
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle', ['require_review' => true]);

    expect($registry->has('composition'))->toBeTrue()
        ->and($registry->for('composition'))->toBeInstanceOf(Binding::class)
        ->and($registry->for('composition')->lineageRef)->toBe('composition.lifecycle')
        // The generic fallback: an unbound type is unmanaged.
        ->and($registry->has('batch-run'))->toBeFalse()
        ->and($registry->for('batch-run'))->toBeNull();
});

it('enforces pick-one arity: a re-bind replaces, never multi-binds', function () {
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle.v1');
    $registry->bind('composition', 'composition.lifecycle.v2');

    expect($registry->all())->toHaveCount(1)
        ->and($registry->for('composition')->lineageRef)->toBe('composition.lifecycle.v2');
});

it('round-trips guard params through the binding (require_review generalised)', function () {
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle', ['require_review' => true, 'max_cells' => 12]);

    $binding = $registry->for('composition');

    expect($binding->param('require_review'))->toBeTrue()
        ->and($binding->param('max_cells'))->toBe(12)
        ->and($binding->param('missing', 'fallback'))->toBe('fallback');

    // A named guard reads its knob straight off the binding params — the v1 config flag, generalised.
    $guard = fn (array $params): bool|string => $params['require_review']
        ? 'Review is required before publishing.'
        : true;

    expect($guard($binding->params))->toBe('Review is required before publishing.');
});

it('resolves an object straight to its binding through the type resolver', function () {
    $resolver = new TypeIdentityResolver(new SchemaTypeProjector);
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle');

    expect($registry->forObject(new BindTestComposition, $resolver))
        ->toBeInstanceOf(Binding::class)
        ->and($registry->forObject(new BindTestComposition, $resolver)->typeKey)->toBe('composition');

    // An untyped object → unmanaged, even against a populated registry.
    expect($registry->forObject(new stdClass, $resolver))->toBeNull();
});

it('unbinding a type returns it to unmanaged', function () {
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle');

    expect($registry->has('composition'))->toBeTrue();

    $registry->unbind('composition');

    expect($registry->has('composition'))->toBeFalse()
        ->and($registry->for('composition'))->toBeNull();
});

it('is registered as a singleton in the container', function () {
    expect(app(WorkflowBindingRegistry::class))->toBe(app(WorkflowBindingRegistry::class));
});

it('reads, replaces and removes a dotted host workflow binding', function () {
    $registry = new WorkflowBindingRegistry;
    $registry->bind('anchor.review', 'anchor.review.v1');

    expect($registry->has('anchor.review'))->toBeTrue()
        ->and($registry->for('anchor.review')->lineageRef)->toBe('anchor.review.v1')
        ->and($registry->tryResolve('beam.workflows.bindings.anchor.review'))->toBe($registry->for('anchor.review'));

    $registry->bind('anchor.review', 'anchor.review.v2');

    expect($registry->all())->toHaveCount(1)
        ->and($registry->for('anchor.review')->lineageRef)->toBe('anchor.review.v2');

    $registry->unbind('anchor.review');

    expect($registry->has('anchor.review'))->toBeFalse()
        ->and($registry->for('anchor.review'))->toBeNull()
        ->and($registry->keys())->toBe([]);
});

it('tolerates malformed string reads while rejecting their registration', function (string $key) {
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle');

    expect($registry->has($key))->toBeFalse()
        ->and($registry->for($key))->toBeNull();

    $registry->forget($key);

    expect($registry->for('composition')->lineageRef)->toBe('composition.lifecycle');
    expect(fn () => $registry->bind($key, 'invalid'))->toThrow(InvalidRegistryKey::class);
})->with(['', 'anchor..review', 'acme/press-release']);

it('preserves explicit URI registry keys through registration, reads and removal', function () {
    $registry = new WorkflowBindingRegistry;
    $key = RelativeUriKey::parse('acme/press-release');
    $binding = new Binding('acme/press-release', 'press-release.lifecycle');
    $registry->register($key, $binding);

    expect($registry->has($key))->toBeTrue()
        ->and($registry->tryResolve($key))->toBe($binding);

    $registry->forget($key);

    expect($registry->has($key))->toBeFalse()
        ->and($registry->keys())->toBe([]);
});
