<?php

namespace Splicewire\Beam\Workflows\Tests\Unit\Migrations;

use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;
use Splicewire\Beam\Workflows\Tests\Fixtures\BeamTenancyPresence;
use Splicewire\Beam\Workflows\Tests\Fixtures\CentralWorkflowMigrationsProvider;

// Pest does not set PHPUnit's Composer entrypoint constant. Its isolated-process runner needs this path
// before it can load the configured bootstrap (beam-accounts' TeamsMigrationFallbackTest precedent).
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__, 3).'/vendor/autoload.php');
}

/**
 * Owner ruling 01c at the migration-registration boundary. A bare application, Laravel's real migrator
 * path API, and no database or host provider booted. beam-tenancy's presence is a test-only alias, made
 * only inside an isolated process.
 *
 * The counts stay distinct: the provider declares 7 migrations, which are 6 create stubs (8 tables) and 1
 * upgrade stub.
 */
final class CentralWorkflowMigrationsTest extends TestCase
{
    private const PUBLISHED_AT = '2026_11_05_120000';

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/beam-workflows-central-'.bin2hex(random_bytes(6));
        mkdir($this->base.'/database/migrations/tenant', 0777, true);
    }

    protected function tearDown(): void
    {
        Application::setInstance(null);
        (new Filesystem)->deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_the_declared_list_is_six_creates_and_one_upgrade_all_under_tenant(): void
    {
        $migrations = BeamWorkflowsServiceProvider::MIGRATIONS;

        $this->assertCount(7, $migrations);
        $this->assertCount(6, array_filter($migrations, fn (string $m): bool => str_starts_with($m, 'tenant/create_')));
        $this->assertSame(['tenant/widen_workflow_awaitings_morph_ids_to_string'], array_values(array_filter(
            $migrations, fn (string $m): bool => ! str_starts_with($m, 'tenant/create_'),
        )));
    }

    public function test_without_beam_tenancy_nothing_is_registered_centrally(): void
    {
        $this->assertFalse(class_exists(BeamTenancyPresence::PROVIDER));
        $this->publish();
        $this->decoys();
        $app = $this->app();

        (new CentralWorkflowMigrationsProvider($app))->boot();

        $this->assertSame([], $app->make('migrator')->paths());
        $app->make(MigrateCommand::class);
        $this->assertSame([], $app->make('migrator')->paths());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_with_beam_tenancy_the_central_pass_gets_exactly_each_declared_stems_published_file(): void
    {
        BeamTenancyPresence::alias();
        $published = $this->publish();
        $app = $this->app();

        (new CentralWorkflowMigrationsProvider($app))->boot();
        $paths = $app->make('migrator')->paths();

        $this->assertSame($published, $paths);
        $this->assertSame(
            array_map('basename', BeamWorkflowsServiceProvider::MIGRATIONS),
            array_map(fn (string $path): string => $this->stem($path), $paths),
        );
        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }
        $this->assertNotContains($this->base.'/database/migrations/tenant', $paths);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_foreign_or_misnamed_file_in_tenant_is_never_registered_centrally(): void
    {
        BeamTenancyPresence::alias();
        $published = $this->publish();
        $decoys = $this->decoys();
        $app = $this->app();

        (new CentralWorkflowMigrationsProvider($app))->boot();
        $app->make(MigrateCommand::class);
        $paths = $app->make('migrator')->paths();

        $this->assertSame($published, $paths);
        foreach ($decoys as $decoy) {
            $this->assertFileExists($decoy);
            $this->assertNotContains($decoy, $paths);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_files_are_read_when_the_migrator_is_built_not_when_the_provider_boots(): void
    {
        BeamTenancyPresence::alias();
        $app = $this->app();

        // A first install boots before it publishes; the publish then lands in the same process.
        (new CentralWorkflowMigrationsProvider($app))->boot();
        $published = $this->publish();

        $this->assertSame($published, $app->make('migrator')->paths());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_migrator_built_before_the_publish_gets_the_published_files_when_migrate_is_built(): void
    {
        BeamTenancyPresence::alias();
        $app = $this->app();
        (new CentralWorkflowMigrationsProvider($app))->boot();

        // On a beam-tenancy host Artisan builds stancl's tenants:migrate, and with it the migrator, when it
        // boots, so before `splicewire:beam:install` publishes anything.
        $migrator = $app->make('migrator');
        $this->assertSame([], $migrator->paths());

        // The install publishes, then `--travel` re-stamps the run's newly published block, then it migrates.
        $travelled = [];
        foreach ($this->publish() as $file) {
            $stamp = \DateTimeImmutable::createFromFormat('Y_m_d_His', substr(basename($file), 0, 17));
            $to = dirname($file).'/'.$stamp->modify('+1 hour')->format('Y_m_d_His').substr(basename($file), 17);
            $this->assertTrue(rename($file, $to));
            $this->assertFileDoesNotExist($file);
            $travelled[] = $to;
        }
        sort($travelled);

        // `migrate` is built when it is called (Laravel loads it lazily), which reads the files as they are now.
        $app->make(MigrateCommand::class);

        $this->assertSame($travelled, $migrator->paths());
        foreach ($migrator->paths() as $path) {
            $this->assertFileExists($path);
        }
    }

    /**
     * The stated boundary (lead 21:50Z; the trait's docblock): files are read when the migrator or the `migrate`
     * command is BUILT.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_boundary_an_already_built_migrate_misses_a_later_publish_while_a_first_migrate_after_it_sees_it(): void
    {
        BeamTenancyPresence::alias();

        // The limit. This process built `migrate` (and the migrator) before the publish, so the cached command,
        // resolved again for a second `migrate`, does not re-read the directory.
        $ran = $this->app();
        (new CentralWorkflowMigrationsProvider($ran))->boot();
        $ran->make(MigrateCommand::class);
        $this->publish();
        $ran->make(MigrateCommand::class);
        $this->assertSame([], $ran->make('migrator')->paths());

        // The supported sequence, as `splicewire:beam:install` orders it. A fresh process on a fresh host: the
        // migrator is built before the publish (stancl's tenants:migrate at Artisan boot), then the process's first
        // `migrate` comes after it. It sees every published file.
        $next = $this->base.'/next-process';
        mkdir($next.'/database/migrations/tenant', 0777, true);
        $first = $this->app($next);
        (new CentralWorkflowMigrationsProvider($first))->boot();
        $migrator = $first->make('migrator');
        $this->assertSame([], $migrator->paths());
        $published = $this->publish($next);
        $first->make(MigrateCommand::class);
        $this->assertSame($published, $migrator->paths());
    }

    /** A bare app whose migrator is bound but not yet built, as Laravel's deferred MigrationServiceProvider leaves it. */
    private function app(?string $base = null): Application
    {
        $app = new Application($base ?? $this->base);
        $app->instance('config', new Repository);
        $app->singleton('migrator', function (): Migrator {
            $resolver = new ConnectionResolver;

            return new Migrator(new DatabaseMigrationRepository($resolver, 'migrations'), $resolver, new Filesystem);
        });
        $app->singleton(MigrateCommand::class, fn ($app): MigrateCommand => new MigrateCommand($app['migrator'], $app['events']));

        return $app;
    }

    /**
     * Publish every declared migration as spatie/laravel-package-tools names it: `<Y_m_d_His>_<stem>.php`,
     * one second apart in declared order.
     *
     * @return list<string>
     */
    private function publish(?string $base = null): array
    {
        $at = \DateTimeImmutable::createFromFormat('Y_m_d_His', self::PUBLISHED_AT);
        $files = [];

        foreach (BeamWorkflowsServiceProvider::MIGRATIONS as $i => $migration) {
            $file = ($base ?? $this->base).'/database/migrations/'.dirname($migration).'/'
                .$at->modify("+{$i} seconds")->format('Y_m_d_His').'_'.basename($migration).'.php';
            file_put_contents($file, "<?php\n");
            $files[] = $file;
        }

        return $files;
    }

    /**
     * Files the central declaration must never take: other packages' `tenant/` migrations, a name that only
     * ENDS in a declared stem, a declared stem without a publish stamp, and a declared stem in another directory.
     *
     * @return list<string>
     */
    private function decoys(): array
    {
        $migrations = $this->base.'/database/migrations';
        @mkdir($migrations.'/shared', 0777, true);

        $decoys = [
            $migrations.'/tenant/2026_08_01_000000_create_cost_events_table.php',
            $migrations.'/tenant/2026_08_01_000001_create_guest_tokens_table.php',
            $migrations.'/tenant/2026_08_01_000002_add_external_ref_to_taxonomy_tables.php',
            $migrations.'/tenant/2026_08_01_000003_legacy_create_workflow_bindings_table.php',
            $migrations.'/tenant/create_workflow_awaitings_table.php',
            $migrations.'/shared/2026_08_01_000004_create_workflow_definition_tables.php',
        ];

        foreach ($decoys as $decoy) {
            file_put_contents($decoy, "<?php\n");
        }

        return $decoys;
    }

    private function stem(string $path): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
    }
}
