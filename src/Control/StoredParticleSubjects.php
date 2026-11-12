<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Http\Request;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Backing\ActorOwnedParticleBacking;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/** Registers existing workflow subjects through their declaration's mandatory read scope. */
final class StoredParticleSubjects
{
    public function __construct(
        private ParticleResourceRegistry $resources,
        private SubjectResolverRegistry $subjects,
        private LifecycleService $lifecycle,
        private WorkflowBindingRegistry $bindings,
        private TypeIdentityResolver $types,
    ) {}

    public function register(): void
    {
        foreach ($this->resources->unfiltered()->all() as $resource) {
            if (! is_string($resource->backing) || ! is_a($resource->backing, ActorOwnedParticleBacking::class, true)) {
                continue;
            }
            $binding = $resource->schemaRef ?? $resource->data;
            if ($binding === null) {
                continue;
            }
            $subject = new BeamParticle;
            $subject->schema_ref = $binding;
            if ($this->bindings->forObject($subject, $this->types) === null) {
                continue;
            }
            $this->subjects->register($resource->key, new StoredParticleFinder($this, $resource->key));
        }
    }

    public function resolve(string $resourceKey, string $id, Request $request): ?BeamParticle
    {
        if (! $this->resources->has($resourceKey)) {
            return null;
        }
        $resource = $this->resources->get($resourceKey);
        if (! app(ResourceReadGuard::class)->inspectReadFor($resource, $request, $request->user())->allowed()) {
            return null;
        }
        $backing = $resource->backing($request);
        if (! $backing instanceof ActorOwnedParticleBacking) {
            return null;
        }
        $subject = $backing->query([])->find($id);

        return $subject instanceof BeamParticle && $this->lifecycle->manages($subject) ? $subject : null;
    }
}
