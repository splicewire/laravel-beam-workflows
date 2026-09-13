<?php

namespace Splicewire\Beam\Workflows\Tests;

use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Calendars\BeamCalendarsServiceProvider;

abstract class CalendarWorkflowTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class, BeamServiceProvider::class,
            DataFiltersServiceProvider::class, BeamCalendarsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('beam.calendars.register_resources', false);
        $app['config']->set('permission-cascade.manage_spatie_teams', false);
        $app['config']->set('data.structure_caching.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create_calendar_actions_table', 'create_calendar_action_attempts_table'] as $name) {
            (require __DIR__.'/../vendor/splicewire/laravel-beam-calendars/database/migrations/shared/'.$name.'.php.stub')->up();
        }
    }
}
