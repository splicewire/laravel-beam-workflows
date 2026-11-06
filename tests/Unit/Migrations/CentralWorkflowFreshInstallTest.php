<?php

namespace Splicewire\Beam\Workflows\Tests\Unit\Migrations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;
use Splicewire\Beam\Workflows\Tests\Fixtures\BeamTenancyPresence;

// Pest does not set PHPUnit's Composer entrypoint constant. Its isolated-process runner needs this path
// before it can load the configured bootstrap (beam-accounts' TeamsMigrationFallbackTest precedent).
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__, 3).'/vendor/autoload.php');
}

/**
 * Owner ruling 01c through Laravel's real commands, on a fresh install with beam-tenancy present (a
 * test-only alias, one isolated process per test).
 *
 * - A first `vendor:publish` and the `migrate` after it, in ONE process (lead Q3), build exactly the workflow
 *   schema on the central connection: the 6 create stubs' 8 tables, through 7 migrations including the
 *   upgrade. Another package's `tenant/` migration beside them stays out.
 * - A tenant pass (`migrate --path=tenant/`, as Stancl's `tenancy.migration_parameters` runs it) is
 *   unchanged: it runs every `tenant/` file on its own connection and writes nothing centrally.
 *
 * Two sqlite `:memory:` connections stand for central and one tenant. The publish lands in a throwaway
 * database directory, never in the testbench skeleton.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CentralWorkflowFreshInstallTest extends TestCase
{
    /** The 6 create stubs' tables. */
    private const WORKFLOW_TABLES = [
        'workflow_action_receipts',
        'workflow_awaitings',
        'workflow_bindings',
        'workflow_definition_lineages',
        'workflow_definition_versions',
        'workflow_reaction_bindings',
        'workflow_reaction_deliveries',
        'workflow_transition_facts',
    ];

    private string $databasePath;

    protected function setUp(): void
    {
        // Before the providers boot: the declaration asks whether beam-tenancy exists when it is wired.
        BeamTenancyPresence::alias();
        $this->databasePath = sys_get_temp_dir().'/beam-workflows-fresh-'.bin2hex(random_bytes(6));
        mkdir($this->databasePath.'/migrations', 0777, true);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->databasePath);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            PopcornServiceProvider::class,
            BeamWorkflowsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Runs before the providers boot, so package-tools computes its publish destinations under this
        // host's own (throwaway) database directory.
        $app->useDatabasePath($this->databasePath);
        $app['config']->set('database.default', 'central');
        $app['config']->set('database.connections.central', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('database.connections.tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    public function test_a_first_publish_then_migrate_in_one_process_builds_exactly_the_workflow_schema_centrally(): void
    {
        // A beam-tenancy host builds its migrator when Artisan boots (stancl's tenants:migrate), before any publish.
        $this->app->make('migrator');

        $this->artisan('vendor:publish', ['--tag' => 'beam-workflows-migrations'])->assertSuccessful()->run();
        $published = $this->tenantFiles();
        $this->assertCount(7, $published);
        $decoys = $this->writeDecoys();

        $this->artisan('migrate')->assertSuccessful()->run();

        $this->assertSame(self::WORKFLOW_TABLES, $this->workflowTables('central'));
        $this->assertFalse(Schema::connection('central')->hasTable('decoy_tenant_only'));
        $this->assertFalse(Schema::connection('central')->hasTable('decoy_suffix_collision'));

        $ran = DB::connection('central')->table('migrations')->pluck('migration')->all();
        $this->assertSame(array_map($this->migrationName(...), $published), array_values(array_intersect($this->sorted($ran), array_map($this->migrationName(...), $published))));
        foreach ($decoys as $decoy) {
            $this->assertNotContains($this->migrationName($decoy), $ran);
        }
    }

    public function test_the_tenant_pass_over_tenant_is_unchanged_and_writes_nothing_centrally(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'beam-workflows-migrations'])->assertSuccessful()->run();
        $this->writeDecoys();
        $tenantFiles = $this->tenantFiles();
        $this->assertCount(9, $tenantFiles);

        $this->artisan('migrate', [
            '--database' => 'tenant',
            '--path' => $this->databasePath.'/migrations/tenant',
            '--realpath' => true,
        ])->assertSuccessful()->run();

        // Every tenant/ file ran in the tenant database: the workflow ones and the other packages' alike.
        $this->assertSame(
            array_map($this->migrationName(...), $tenantFiles),
            $this->sorted(DB::connection('tenant')->table('migrations')->pluck('migration')->all()),
        );
        $this->assertSame(self::WORKFLOW_TABLES, $this->workflowTables('tenant'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('decoy_tenant_only'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('decoy_suffix_collision'));

        // The central declaration did not leak into the tenant pass, and the tenant pass touched nothing central.
        $this->assertSame([], $this->workflowTables('central'));
    }

    /** @return list<string> */
    private function tenantFiles(): array
    {
        $files = glob($this->databasePath.'/migrations/tenant/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Two migrations that must never run centrally: another package's tenant-only table, and a file whose
     * name only ENDS in a declared stem.
     *
     * @return list<string>
     */
    private function writeDecoys(): array
    {
        $tenant = $this->databasePath.'/migrations/tenant/';
        $decoys = [
            $tenant.'2026_01_01_000000_create_decoy_tenant_only_table.php' => 'decoy_tenant_only',
            $tenant.'2026_01_01_000001_legacy_create_workflow_bindings_table.php' => 'decoy_suffix_collision',
        ];

        foreach ($decoys as $file => $table) {
            file_put_contents($file, <<<PHP
                <?php

                use Illuminate\\Database\\Migrations\\Migration;
                use Illuminate\\Database\\Schema\\Blueprint;
                use Illuminate\\Support\\Facades\\Schema;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::create('{$table}', function (Blueprint \$table) {
                            \$table->id();
                        });
                    }
                };

                PHP);
        }

        return array_keys($decoys);
    }

    /** @return list<string> */
    private function workflowTables(string $connection): array
    {
        $tables = array_values(array_filter(
            array_column(Schema::connection($connection)->getTables(), 'name'),
            fn (string $table): bool => str_starts_with($table, 'workflow_'),
        ));
        sort($tables);

        return $tables;
    }

    private function migrationName(string $file): string
    {
        return basename($file, '.php');
    }

    /** @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }
}
