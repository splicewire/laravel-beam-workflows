<?php

use Splicewire\Beam\Workflows\Tests\CircuitTestCase;
use Splicewire\Beam\Workflows\Tests\TestCase;

// Most suites boot the Display substrate only (no Circuit dependency).
uses(TestCase::class)->in('BootTest.php', 'Display', 'Blueprint', 'Type', 'Binding', 'Definition', 'Lifecycle');

// Seam B runs through the real Circuit kernel.
uses(CircuitTestCase::class)->in('Control');
