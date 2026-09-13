<?php

namespace Splicewire\Beam\Workflows\History;

use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Workflows\History\Ops\ReadWorkflowHistory;

class WorkflowHistoryResources
{
    public static function mount(string $uri = 'workflow-history'): void
    {
        $ops = [ReadWorkflowHistory::class];
        app(AttributedParticleDiscovery::class)->discover($ops);
        Particle::ops($uri, 'workflow-history', $ops);
    }
}
