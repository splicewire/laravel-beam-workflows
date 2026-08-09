<?php

namespace Splicewire\Beam\Workflows\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

class BeamWorkflowsMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-workflows';
    }

    protected function serviceProviderClass(): string
    {
        return BeamWorkflowsServiceProvider::class;
    }
}
