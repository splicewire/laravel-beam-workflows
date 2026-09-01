<?php

namespace Splicewire\Beam\Workflows\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Doctor\MultiPlaceMarkingAudit;
use Splicewire\Beam\Workflows\Tests\TestCase;

/**
 * The advisory half of the single-place-persistence constraint: it reads the host's blueprint
 * registry and reports a declaration a lifecycle could never persist, before anyone attempts the
 * transition that would be refused.
 */
class MultiPlaceMarkingAuditTest extends TestCase
{
    public function test_an_empty_registry_is_inconclusive_rather_than_clean(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive, 'A host registering no blueprints has measured nothing, not measured clean.');
    }

    public function test_it_passes_a_single_place_blueprint(): void
    {
        app(WorkflowRegistry::class)->register('parcel.linear', WorkflowBlueprint::fromArray([
            'name' => 'parcel.linear',
            'places' => ['received', 'shipped'],
            'transitions' => [['name' => 'ship', 'from' => 'received', 'to' => 'shipped']],
        ]));

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
    }

    public function test_it_warns_on_a_transition_that_produces_two_places(): void
    {
        app(WorkflowRegistry::class)->register('parcel.fork', WorkflowBlueprint::fromArray([
            'name' => 'parcel.fork',
            'places' => ['received', 'weighing', 'labelling'],
            'transitions' => [['name' => 'split', 'from' => 'received', 'to' => ['weighing', 'labelling']]],
        ]));

        $findings = $this->audit()->run();

        // WARN, not FAIL: which blueprints are registered is a fact about the host, and a
        // multi-place blueprint is legal on the node seam.
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('split', $findings[0]->detail);
        $this->assertStringContainsString('labelling', $findings[0]->detail);
    }

    public function test_it_warns_on_a_multi_place_initial_marking(): void
    {
        app(WorkflowRegistry::class)->register('parcel.multi-start', WorkflowBlueprint::fromArray([
            'name' => 'parcel.multi-start',
            'places' => ['weighing', 'labelling', 'shipped'],
            'initial' => ['weighing', 'labelling'],
            'transitions' => [['name' => 'ship', 'from' => ['weighing', 'labelling'], 'to' => 'shipped']],
        ]));

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('initial marking', $findings[0]->detail);
    }

    protected function audit(): MultiPlaceMarkingAudit
    {
        return new MultiPlaceMarkingAudit(app(WorkflowRegistry::class));
    }
}
