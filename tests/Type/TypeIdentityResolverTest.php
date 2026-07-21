<?php

use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\HasSchemaType;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;
use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/*
 * Ticket 01 — the socket's selector. An object resolves to a workflow-type key through ONE of two
 * doors (model contract / schema projection), both landing on the same namespace; anything else is
 * unmanaged (null). These are behavioural: no symfony/workflow, no binding registry yet.
 */

/** A model-shaped consumer: declares its identity via the contract + trait defaults. */
class FakeManagedComposition implements WorkflowManaged
{
    use WorkflowManagedTrait;

    public function workflowType(): string
    {
        return 'composition';
    }
}

/** A model that overrides the attribute defaults. */
class FakeManagedBatch implements WorkflowManaged
{
    use WorkflowManagedTrait;

    public function workflowType(): string
    {
        return 'batch-run';
    }

    public function workflowStatusAttribute(): string
    {
        return 'state';
    }

    public function workflowVersionAttribute(): string
    {
        return 'def_version';
    }
}

/** A schema-shaped record reaching the resolver through the second door. */
class FakeSchemaRecord implements HasSchemaType
{
    public function __construct(private ?string $type) {}

    public function xStudType(): ?string
    {
        return $this->type;
    }
}

/** A plain object the workflow layer knows nothing about. */
class FakeUnmanagedThing
{
    public string $status = 'whatever';
}

it('resolves a managed model to its declared type key (first door)', function () {
    $resolver = app(TypeIdentityResolver::class);

    expect($resolver->forObject(new FakeManagedComposition))->toBe('composition')
        ->and($resolver->isResolvable(new FakeManagedComposition))->toBeTrue();
});

it('supplies status/version attribute defaults from the trait', function () {
    $composition = new FakeManagedComposition;

    expect($composition->workflowStatusAttribute())->toBe('status')
        ->and($composition->workflowVersionAttribute())->toBe('workflow_version');
});

it('lets a model override the projection + pin attributes', function () {
    $batch = new FakeManagedBatch;

    expect($batch->workflowType())->toBe('batch-run')
        ->and($batch->workflowStatusAttribute())->toBe('state')
        ->and($batch->workflowVersionAttribute())->toBe('def_version');
});

it('resolves an unmanaged object to null (the generic fallback)', function () {
    $resolver = app(TypeIdentityResolver::class);

    expect($resolver->forObject(new FakeUnmanagedThing))->toBeNull()
        ->and($resolver->isResolvable(new FakeUnmanagedThing))->toBeFalse();
});

it('projects a schema record through the second door only when a mapping exists', function () {
    /** @var SchemaTypeProjector $projector */
    $projector = app(SchemaTypeProjector::class);
    $projector->map('article', 'composition');

    $resolver = app(TypeIdentityResolver::class);

    // Mapped schema type → the shared workflow key namespace.
    expect($resolver->forObject(new FakeSchemaRecord('article')))->toBe('composition');

    // An unmapped schema type is unmanaged by default (the door is built, not opened).
    expect($resolver->forObject(new FakeSchemaRecord('unmapped-thing')))->toBeNull();

    // A record carrying no schema type is unmanaged.
    expect($resolver->forObject(new FakeSchemaRecord(null)))->toBeNull();
});

it('treats a blank type key as unmanaged', function () {
    $blank = new class implements WorkflowManaged
    {
        use WorkflowManagedTrait;

        public function workflowType(): string
        {
            return '   ';
        }
    };

    expect(app(TypeIdentityResolver::class)->forObject($blank))->toBeNull();
});

/** A model that is ALSO schema-driven — resolves through both doors. */
class FakeSchemaDrivenComposition implements HasSchemaType, WorkflowManaged
{
    use WorkflowManagedTrait;

    public function __construct(private ?string $schemaType) {}

    public function workflowType(): string
    {
        return 'composition';
    }

    public function xStudType(): ?string
    {
        return $this->schemaType;
    }
}

it('ranks the schema identity above the class identity (specific first), with the class as fallback', function () {
    $projector = new SchemaTypeProjector(identityByDefault: true);
    $resolver = new TypeIdentityResolver($projector);

    // Schema-driven → schema key is primary, class key is the fallback candidate.
    $driven = new FakeSchemaDrivenComposition('article');
    expect($resolver->candidatesFor($driven))->toBe(['article', 'composition'])
        ->and($resolver->forObject($driven))->toBe('article');

    // Not schema-driven → only the class key.
    $plain = new FakeSchemaDrivenComposition(null);
    expect($resolver->candidatesFor($plain))->toBe(['composition'])
        ->and($resolver->forObject($plain))->toBe('composition');
});

it('picks the first BOUND candidate: schema binding wins, else the class binding', function () {
    $resolver = new TypeIdentityResolver(new SchemaTypeProjector(identityByDefault: true));
    $registry = new WorkflowBindingRegistry;
    $registry->bind('composition', 'composition.lifecycle');

    $driven = new FakeSchemaDrivenComposition('article');

    // No schema binding yet → falls back to the class binding.
    expect($registry->forObject($driven, $resolver)->typeKey)->toBe('composition');

    // Bind the schema → it now wins over the class binding.
    $registry->bind('article', 'article.lifecycle');
    expect($registry->forObject($driven, $resolver)->typeKey)->toBe('article');

    // A different schema with no binding still falls back to the class.
    expect($registry->forObject(new FakeSchemaDrivenComposition('memo'), $resolver)->typeKey)->toBe('composition');
});

it('supports an identity-by-default projector for a schema-first host', function () {
    $projector = new SchemaTypeProjector(identityByDefault: true);

    // Unmapped → projects to itself; an explicit null disowns.
    expect($projector->project('calendar-item'))->toBe('calendar-item')
        ->and($projector->map('draft-only', null)->project('draft-only'))->toBeNull();
});
