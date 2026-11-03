<?php

namespace Splicewire\Beam\Workflows\Tests\Registries;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * laravel-beam-workflows adopts the package-tier registry docs check over the whole of `src/`: every
 * declared root has its page under `docs/registries/`, and every page's generated facts block is
 * byte-identical to a fresh render of its `#[IsRegistry]` declaration.
 *
 * The coverage count is asserted too, so an inactive check (no `docs/registries/` directory) or a
 * scan that finds nothing cannot pass by reporting no failures.
 */
class RegistryDocsAdoptionTest extends TestCase
{
    public function test_every_declared_root_is_documented_with_its_facts_in_step(): void
    {
        $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

        $this->assertSame([], $report->failures(), $report->summary());
        $this->assertCount(7, $report->coverage, 'the six beam.workflows.* roots and workflow: '.implode(', ', array_keys($report->coverage)));
    }
}
