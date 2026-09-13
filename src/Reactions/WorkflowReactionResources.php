<?php

namespace Splicewire\Beam\Workflows\Reactions;

use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Workflows\Reactions\Ops\ConfigureWorkflowReaction;
use Splicewire\Beam\Workflows\Reactions\Ops\DisableWorkflowReaction;
use Splicewire\Beam\Workflows\Reactions\Ops\ListWorkflowReactions;
use Splicewire\Beam\Workflows\Reactions\Ops\RetryWorkflowReaction;

class WorkflowReactionResources
{
    public static function mount(string $uri = 'workflow-reactions'): void
    {
        $ops = [ConfigureWorkflowReaction::class, DisableWorkflowReaction::class, ListWorkflowReactions::class, RetryWorkflowReaction::class];
        app(AttributedParticleDiscovery::class)->discover($ops);
        Particle::ops($uri, 'workflow-reactions', $ops);
    }
}
