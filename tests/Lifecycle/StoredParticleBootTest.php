<?php

namespace Splicewire\Beam\Workflows\Tests\Lifecycle;

use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleAccess;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleBacking;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Tests\TestCase;
use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;

class StoredParticleBootTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataSchemasServiceProvider::class, BeamServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('beam.core.resources.classes', [BootStoredNote::class]);
        $app->booting(function () use ($app): void {
            $app->make(SchemaTypeProjector::class)->map('https://example.test/schemas/boot-note', 'boot-note');
            $app->make(WorkflowBindingRegistry::class)->bind('boot-note', 'boot-note.lifecycle');
        });
    }

    public function test_a_declared_stored_resource_registers_its_workflow_subject_during_provider_boot(): void
    {
        $this->assertTrue(app(SubjectResolverRegistry::class)->has('boot-notes'));
    }
}

#[ParticleResource(
    key: 'boot-notes', backing: ActorOwnedParticleBacking::class, data: BootStoredNote::class,
    input: BootStoredNoteInput::class, schemaRef: 'https://example.test/schemas/boot-note/1',
    policy: ActorOwnedParticleAccess::WRITE, readPolicy: ActorOwnedParticleAccess::class,
)]
class BootStoredNote extends Data
{
    public function __construct(public string $id, public string $title) {}
}

class BootStoredNoteInput extends Data
{
    public function __construct(public string $title) {}
}
