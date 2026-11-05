<?php

namespace Splicewire\Beam\Workflows\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;
use Splicewire\Beam\Workflows\Concerns\WiresCentralWorkflowMigrations;

/**
 * Boots only the owning concern, with the real provider's migration list, so a test can observe Laravel's
 * actual migrator path API on a bare application (beam-accounts' `TeamsMigrationFallbackTest` shape).
 */
final class CentralWorkflowMigrationsProvider extends ServiceProvider
{
    use WiresCentralWorkflowMigrations;

    public function boot(): void
    {
        $this->wireCentralWorkflowMigrations(BeamWorkflowsServiceProvider::MIGRATIONS);
    }
}
