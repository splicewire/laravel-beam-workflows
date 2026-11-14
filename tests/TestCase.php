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
            // localization 37: beam's provider, as every production host boots it, so its single namespace allocator
            // hints this package's shipped lang/ and the shared MessagePresenters registry is bound (test harness only).
            // data-schemas BEFORE beam, as every production host discovers it and as beam's own harness boots it: beam's
            // packageBooted APPENDS to `data-schemas.strategies`, and with that key unset the append would replace the
            // generator's default strategies (localization 37 leg finding; a beam follow-up on the 12/13 list).
            \Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class,
            \Splicewire\Beam\BeamServiceProvider::class,
            BeamWorkflowsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', static::suiteConnection());
    }

    /**
     * sqlite `:memory:` by default. `PG_TEST_DATABASE` re-points the whole suite at a real Postgres
     * database (laravel-beam-tenancy's `PostgresTestCase` precedent; `PG_TEST_HOST/PORT/USERNAME/PASSWORD`
     * optional), because sqlite stores any value in any column and so cannot see a key-type mismatch —
     * an integer subject id in a `uuid` column is invisible here and a hard error there.
     *
     * Name a THROWAWAY database this run owns: every table in it is dropped before each test (see
     * {@see self::setUp()}), the way a fresh `:memory:` database starts empty. So the name must carry a
     * disposable prefix ({@see self::refuseNonDisposableDatabase()}), checked here before anything connects
     * and again beside the drop — a direct `pest` run can never wipe a real database.
     *
     * @return array<string, mixed>
     */
    protected static function suiteConnection(): array
    {
        $database = getenv('PG_TEST_DATABASE');

        if (! $database) {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        static::refuseNonDisposableDatabase($database);

        return [
            'driver' => 'pgsql',
            'host' => getenv('PG_TEST_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('PG_TEST_PORT') ?: 5432),
            'database' => $database,
            'username' => getenv('PG_TEST_USERNAME') ?: 'postgres',
            'password' => getenv('PG_TEST_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * The suite drops every table in a Postgres database, so it refuses one whose name does not start with
     * a disposable prefix and run past it: `BEAM_DEV_DB_PREFIX` when set (the family's scratch-database
     * prefix), otherwise `ttc_` or `test_`.
     */
    protected static function refuseNonDisposableDatabase(string $database): void
    {
        $prefixes = getenv('BEAM_DEV_DB_PREFIX') ? [getenv('BEAM_DEV_DB_PREFIX')] : ['ttc_', 'test_'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($database, $prefix) && strlen($database) > strlen($prefix)) {
                return;
            }
        }

        throw new \RuntimeException(
            "Refusing PG_TEST_DATABASE `{$database}`: the suite drops every table in it, so it must be a throwaway "
            .'database named with a disposable prefix ('.implode(' or ', $prefixes).').'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A server database outlives the test; `:memory:` does not. Start each test as empty as sqlite does —
        // but only in a database whose name says it is disposable (re-checked against the live connection).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            static::refuseNonDisposableDatabase((string) Schema::getConnection()->getDatabaseName());
            Schema::dropAllTables();
        }

        $this->createActivityLogTable();
        $this->createDefinitionStoreTables();
        (require __DIR__.'/../database/migrations/tenant/create_workflow_transition_facts_table.php.stub')->up();
        (require __DIR__.'/../database/migrations/tenant/create_workflow_action_receipts_table.php.stub')->up();
        (require __DIR__.'/../database/migrations/tenant/create_workflow_reactions_tables.php.stub')->up();

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
        // anything it was written to assert. Built by the shipped stub itself rather than a hand copy,
        // so the suite's subject-key type can never drift from what a host publishes (a convergent
        // guard: a no-op when the table already holds the declared shape).
        (require __DIR__.'/../database/migrations/tenant/create_workflow_awaitings_table.php.stub')->up();

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
