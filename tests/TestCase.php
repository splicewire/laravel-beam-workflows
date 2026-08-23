<?php

namespace Splicewire\Beam\Workflows\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * The workflows package boots on top of the activity-log substrate (Seam A's Display
     * read-model). The Control-seam node (Seam B) registers only when the circuit-engine is
     * present, so the base suite deliberately omits it — a host that only wants the status
     * substrate boots with exactly these providers.
     *
     * `LaravelDataServiceProvider` publishes spatie/laravel-data's own `config('data')` —
     * required for `Data::from($eloquentModel)`'s model→Data mapping (the zero-glue
     * `#[ParticleResource]` tier, `WorkflowAwaitingRowData`) to resolve
     * `config('data.validation_strategy')`; every Data class in this package was previously only
     * ever constructed directly (`new WorkflowBlueprintData(...)`), never via `::from()`, so this
     * gap was never hit before.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            // laravel-popcorn binds RegistryIndex as a SINGLETON. Without it the index is
            // auto-resolvable but unshared, so an owner's describe() lands on a throwaway and the
            // registry is unroutable — index membership as a function of host composition (04 D1).
            PopcornServiceProvider::class,
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
        $this->createDefinitionStoreTables();

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

    /**
     * The versioned definition store (ticket 03). In the host these are tenant-scoped migrations
     * (database/migrations/tenant); the package suite creates them directly, mirroring the real
     * shape so the DefinitionStore has somewhere to write its immutable versions.
     */
    protected function createDefinitionStoreTables(): void
    {
        if (! Schema::hasTable('workflow_definition_lineages')) {
            Schema::create('workflow_definition_lineages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('key')->unique();
                $table->string('name');
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
        }

        // `ClearAwaitingsOnTransition` deletes from this table on EVERY applied transition, so it is
        // on the hot path of the lifecycle whether or not a suite stamps awaitings of its own.
        // Without it, any test that applies a transition dies on a missing table rather than on
        // anything it was written to assert.
        if (! Schema::hasTable('workflow_awaitings')) {
            Schema::create('workflow_awaitings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuidMorphs('subject');
                $table->string('place');
                $table->string('principal');
                $table->nullableUuidMorphs('parent');
                $table->timestamp('created_at')->nullable();
                $table->unique(['subject_type', 'subject_id', 'place', 'principal'], 'workflow_awaitings_natural_key');
            });
        }

        if (! Schema::hasTable('workflow_definition_versions')) {
            Schema::create('workflow_definition_versions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('lineage_id');
                $table->unsignedInteger('version');
                $table->json('blueprint');
                $table->boolean('is_active')->default(false);
                $table->timestamps();

                $table->unique(['lineage_id', 'version']);
                $table->index(['lineage_id', 'is_active']);
            });
        }
    }
}
