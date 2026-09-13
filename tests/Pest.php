<?php

use Splicewire\Beam\Workflows\Tests\CircuitTestCase;
use Splicewire\Beam\Workflows\Tests\TestCase;

// Most suites boot the Display substrate only (no Circuit dependency).
uses(TestCase::class)->in('BootTest.php', 'Awaiting', 'Display', 'Blueprint', 'Type', 'Binding', 'Definition', 'Lifecycle', 'Migration', 'Admin', 'Actions', 'Reactions');

// Seam B runs through the real Circuit kernel.
uses(CircuitTestCase::class)->in('Control', 'CircuitActions');

uses(Splicewire\Beam\Workflows\Tests\CalendarWorkflowTestCase::class)->in('CalendarActions');
