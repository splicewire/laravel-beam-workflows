<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleAccess;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\StoredParticleSubjects;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

beforeEach(function () {
    app()->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
    app()->register(Splicewire\Beam\BeamServiceProvider::class);
    (require __DIR__.'/../../vendor/splicewire/laravel-beam/database/migrations/shared/create_beam_particles_table.php.stub')->up();
    app(SchemaTypeProjector::class)->map('https://example.test/schemas/note', 'owned-note');
    app(WorkflowBindingRegistry::class)->bind('owned-note', 'note.lifecycle');
    app(DefinitionStore::class)->ensureSystemLineage('note.lifecycle', 'Note', storedNoteBlueprint());
});

function storedNoteBlueprint(): WorkflowBlueprint
{
    return WorkflowBlueprint::fromArray([
        'name' => 'note.lifecycle',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]);
}

function storedWorkflowNote(string $schema = 'https://example.test/schemas/note/1'): BeamParticle
{
    return BeamParticle::create([
        'schema_ref' => $schema,
        'payload' => ['title' => 'Keep this content'],
        'meta' => ['owner' => ['type' => 'user', 'id' => '7'], 'namespace' => 'notes'],
    ]);
}

it('resolves a stored particle by declared schema mapping without a model fallback', function () {
    $note = storedWorkflowNote();
    expect(app(TypeIdentityResolver::class)->candidatesFor($note))->toBe(['owned-note']);
    expect(app(TypeIdentityResolver::class)->candidatesFor(storedWorkflowNote('https://example.test/schemas/other/1')))->toBe([]);
});

it('persists marking and immutable definition pin in metadata without touching payload or owner', function () {
    $note = storedWorkflowNote();
    $before = $note->toVersionSnapshot();
    $result = app(LifecycleService::class)->transition($note, 'publish');
    expect($result->applied)->toBeTrue();
    $note = $note->fresh();
    expect($note->meta['workflow']['marking'])->toBe('published')
        ->and($note->meta['workflow']['version'])->toBe(app(DefinitionStore::class)->activeVersion('note.lifecycle')->id)
        ->and($note->meta['owner'])->toBe($before['meta']['owner'])
        ->and($note->meta['namespace'])->toBe('notes')
        ->and($note->getRawOriginal('payload'))->toBe(json_encode($before['payload']))
        ->and(app(LifecycleService::class)->available($note))->toBe([]);
});

it('pins a definition in metadata and refuses broken pins', function () {
    $note = storedWorkflowNote();
    $pin = app(LifecycleService::class)->pinDefinition($note);
    expect($pin)->toBe(app(DefinitionStore::class)->activeVersion('note.lifecycle')->id)
        ->and($note->fresh()->meta['workflow']['version'])->toBe($pin);
    $meta = $note->meta;
    $meta['workflow']['version'] = 'missing';
    $note->meta = $meta;
    $note->save();
    expect(app(LifecycleService::class)->transition($note, 'publish')->applied)->toBeFalse()
        ->and($note->fresh()->meta['workflow']['version'])->toBe('missing');
});

it('leaves unbound schemas unmanaged and illegal transitions unchanged', function () {
    $other = storedWorkflowNote('https://example.test/schemas/other/1');
    $before = $other->fresh()->getAttributes();
    expect(app(LifecycleService::class)->transition($other, 'publish')->applied)->toBeFalse()
        ->and($other->fresh()->getAttributes())->toBe($before);
    $note = storedWorkflowNote();
    expect(app(LifecycleService::class)->transition($note, 'missing')->applied)->toBeFalse()
        ->and($note->fresh()->meta)->not->toHaveKey('workflow');
});

it('keeps an existing pin on its graph when a new definition becomes active', function () {
    $note = storedWorkflowNote();
    $lifecycle = app(LifecycleService::class);
    $pin = $lifecycle->pinDefinition($note);
    app(DefinitionStore::class)->fork('note.lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'note.lifecycle',
        'places' => ['draft', 'archived'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'archive', 'from' => 'draft', 'to' => 'archived']],
    ]));
    expect($lifecycle->available($note->fresh()))->toBe(['publish'])
        ->and($lifecycle->available(storedWorkflowNote()))->toBe(['archive']);
    expect($lifecycle->transition($note, 'publish')->applied)->toBeTrue()
        ->and($note->fresh()->meta['workflow']['version'])->toBe($pin);
});

it('runs normal transition guards before any workflow metadata is persisted', function () {
    app(GuardRegistry::class)->register('note-author', fn (): string => 'Only the author may publish.');
    app(DefinitionStore::class)->fork('note.lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'note.lifecycle',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'note-author']],
    ]));
    $note = storedWorkflowNote();
    $before = $note->fresh()->getAttributes();
    $result = app(LifecycleService::class)->transition($note, 'publish');
    expect($result->applied)->toBeFalse()
        ->and($result->blockers)->toContain('Only the author may publish.')
        ->and($note->fresh()->getAttributes())->toBe($before);
});

it('resolves workflow subjects through schema and trusted request owner scope', function () {
    foreach (['notes' => 'note', 'others' => 'other'] as $key => $schema) {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key, backing: ActorOwnedParticleBacking::class,
            data: StoredWorkflowRead::class, input: StoredWorkflowInput::class,
            schemaRef: 'https://example.test/schemas/'.$schema.'/1',
            policy: ActorOwnedParticleAccess::WRITE, readPolicy: ActorOwnedParticleAccess::class,
        ));
    }
    $owner = new User;
    $owner->id = 7;
    $other = new User;
    $other->id = 8;
    $request = Request::create('/workflow-subjects/notes');
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $owner);
    $note = storedWorkflowNote('https://example.test/schemas/note');
    $note->meta = ['owner' => ActorOwnedParticleAccess::owner($owner)];
    $note->save();

    $resource = app(ParticleResourceRegistry::class)->get('notes');
    $verdict = app(Splicewire\Beam\Authorization\ResourceReadGuard::class)->inspectReadFor($resource, $request, $owner);
    expect($verdict->allowed())->toBeTrue($verdict->message() ?? 'read boundary');
    expect($resource->backing($request)->query([])->find($note->id)?->id)->toBe($note->id);
    $subjects = app(StoredParticleSubjects::class);
    $subjects->register();
    expect(app(SubjectResolverRegistry::class)->has('notes'))->toBeTrue()
        ->and(app(SubjectResolverRegistry::class)->has('others'))->toBeFalse();
    $resolved = app(WorkflowActuator::class)->subject('notes', $note->id);
    expect($resolved?->id)->toBe($note->id);
    expect(app(WorkflowActuator::class)->transition($resolved, 'publish')->applied)->toBeTrue();

    $foreign = clone $request;
    $foreign->setUserResolver(fn () => $other);
    expect($subjects->resolve('notes', $note->id, $foreign))->toBeNull()
        ->and($subjects->resolve('others', $note->id, $request))->toBeNull();
    expect(app(WorkflowActuator::class)->subject('notes', $note->id, $foreign))->toBeNull();
    app()->instance('request', $foreign);
    $foreign->setUserResolver(fn () => $other);
    expect(app(WorkflowActuator::class)->subject('notes', $note->id, $request)?->id)->toBe($note->id)
        ->and(app(WorkflowActuator::class)->subject('notes', $note->id))->toBeNull();
    $guest = clone $request;
    $guest->setUserResolver(fn () => null);
    expect($subjects->resolve('notes', $note->id, $guest))->toBeNull();
});

it('keeps missing legacy attributes null under Eloquent strict mode', function () {
    $model = new StoredWorkflowLegacy;
    $model->setRawAttributes(['id' => 1], true);
    $model->exists = true;
    $strict = Illuminate\Database\Eloquent\Model::preventsAccessingMissingAttributes();
    Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes();
    try {
        expect(Splicewire\Beam\Workflows\Control\SubjectState::version($model))->toBeNull()
            ->and(Splicewire\Beam\Workflows\Control\SubjectState::marking($model))->toBeNull();
    } finally {
        Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes($strict);
    }
});

it('preserves a real workflow marking and pin when ownerless content is restored', function () {
    $note = storedWorkflowNote();
    $note->meta = ['namespace' => 'notes'];
    $note->save();
    $snapshot = $note->toVersionSnapshot();
    Illuminate\Support\Facades\Event::fake([Splicewire\Beam\Events\BeamParticlePersisted::class]);
    expect(app(LifecycleService::class)->transition($note, 'publish')->applied)->toBeTrue();
    $state = $note->fresh()->meta['workflow'];
    $note->restoreVersionSnapshot($snapshot);
    expect($note->fresh()->meta['workflow'])->toBe($state)
        ->and(app(LifecycleService::class)->available($note->fresh()))->toBe([]);
    Illuminate\Support\Facades\Event::assertNotDispatched(Splicewire\Beam\Events\BeamParticlePersisted::class);
});

it('refuses automatic durable actions on stored particles without a trusted request actor', function () {
    app(ParticleResourceRegistry::class)->register(new ParticleResource(
        key: 'worker-notes', backing: ActorOwnedParticleBacking::class,
        data: StoredWorkflowRead::class, input: StoredWorkflowInput::class,
        schemaRef: 'https://example.test/schemas/note/1',
        policy: ActorOwnedParticleAccess::WRITE, readPolicy: ActorOwnedParticleAccess::class,
    ));
    app(StoredParticleSubjects::class)->register();
    $request = Request::create('/worker');
    app()->instance('request', $request);
    $request->setUserResolver(fn () => null);
    app()->instance(Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority::class,
        new class implements Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority
        {
            public function authorize(Illuminate\Database\Eloquent\Model $subject, string $transition, Splicewire\Beam\Workflows\Actions\WorkflowActionContext $context): void
            {
                throw new LogicException('An actorless stored subject must not reach action authority.');
            }
        });
    $note = storedWorkflowNote('https://example.test/schemas/note');
    $before = $note->fresh()->getAttributes();
    $data = new Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('worker-notes', $note->id, 'publish', app(DefinitionStore::class)->activeVersion('note.lifecycle')->id);
    $context = new Splicewire\Beam\Workflows\Actions\WorkflowActionContext('opaque:user7', 'opaque:user7', 'test-host');
    $actions = app(Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    expect(fn () => $actions->prepare($data, $context))->toThrow(InvalidArgumentException::class, 'no longer resolves');
    $result = $actions->execute('actorless-owned-note', $data, $context);
    expect($result->applied)->toBeFalse()
        ->and($result->blockers)->toContain('The workflow subject no longer resolves.')
        ->and($note->fresh()->getAttributes())->toBe($before);
});

class StoredWorkflowRead extends Data
{
    public function __construct(public string $id, public string $title) {}
}

class StoredWorkflowInput extends Data
{
    public function __construct(public string $title) {}
}

class StoredWorkflowLegacy extends Illuminate\Database\Eloquent\Model implements Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged
{
    use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged;

    public function workflowType(): string
    {
        return 'legacy-note';
    }
}
