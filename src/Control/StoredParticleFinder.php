<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Http\Request;
use Splicewire\Beam\Models\BeamParticle;

/** A callable registry entry that also accepts a transport's explicit actor-bound request. */
final readonly class StoredParticleFinder
{
    public function __construct(private StoredParticleSubjects $subjects, private string $resource) {}

    public function __invoke(string $id): ?BeamParticle
    {
        return $this->resolve($id, app(Request::class));
    }

    public function resolve(string $id, Request $request): ?BeamParticle
    {
        return $this->subjects->resolve($this->resource, $id, $request);
    }
}
