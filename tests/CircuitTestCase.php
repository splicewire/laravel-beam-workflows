<?php

namespace Splicewire\Beam\Workflows\Tests;

use Splicewire\Circuits\CircuitEngineServiceProvider;

/**
 * The Seam B test harness: the base substrate PLUS the real Circuit kernel, so a state-machine
 * node is exercised through the kernel's actual capability-dispatch path (CapabilityDispatcher +
 * InvocableRegistry + StructuralPortValidator), not a stand-in. Booting CircuitEngineServiceProvider
 * binds the registry our provider's boot() registers the `workflow.apply` node into.
 */
abstract class CircuitTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            CircuitEngineServiceProvider::class,
        ];
    }
}
