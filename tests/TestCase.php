<?php

namespace Splicewire\Beam\Workflows\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * The workflows package boots on top of the activity-log substrate (Seam A's Display
     * read-model). The Control-seam node (Seam B) registers only when the circuit-engine is
     * present, so the base suite deliberately omits it — a host that only wants the status
     * substrate boots with exactly these providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ActivitylogServiceProvider::class,
            BeamWorkflowsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createActivityLogTable();

        Schema::create('fake_processes', function (Blueprint $table) {
            $table->id();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * spatie/laravel-activitylog ships a publish-only migration; testbench does not auto-run it.
     * Create the table directly so Seam A's projection has somewhere to land — this mirrors the
     * real activitylog **v5** `activity_log` schema (v5 dropped the batch system, so there is NO
     * `batch_uuid` column; run grouping rides `properties.run_id` instead — see StatusEmitter).
     */
    protected function createActivityLogTable(): void
    {
        if (Schema::hasTable('activity_log')) {
            return;
        }

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
