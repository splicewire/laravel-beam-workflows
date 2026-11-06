<?php

namespace Splicewire\Beam\Workflows\Tests\Doctor;

use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;
use Splicewire\Beam\Workflows\Tests\TestCase;

/**
 * beam-workflows' own operator check: its migrations must stay publish-only .stub files. Mirrors
 * beam-core's `BeamCoreStubMigrationsTest` shape (`rushing/php-package-topology`'s
 * `AssertsDeclaredTopology` precedent) — a thin test wrapping a shared engine, declaring only "which
 * package is mine."
 */
class BeamWorkflowsStubMigrationsTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_workflows_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsPackage(): ?string
    {
        return 'splicewire/laravel-beam-workflows';
    }

    protected function stubMigrationsProvider(): ?string
    {
        return BeamWorkflowsServiceProvider::class;
    }
}
