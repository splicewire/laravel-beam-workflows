<?php

namespace Splicewire\Beam\Workflows\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;

/**
 * The workflow schema's CENTRAL half on a beam-tenancy host (owner ruling 01c, make-surface ticket 01,
 * 2026-11-05). Central operator flows run workflows outside any tenant — beam-ux's Release, beam-market's
 * listing review, the beam starter's service desk — so the central database needs the same tables the
 * tenant pass builds. Without beam-tenancy, beam-accounts' fallback (`WiresTeamsMigrations`) already runs
 * the host's whole published `tenant/` directory centrally, and this adds nothing. beam-tenancy turns that
 * fallback off by existing, so this package declares its own half instead.
 *
 * - **When:** only while `Splicewire\Beam\Tenancy\BeamTenancyServiceProvider` exists, the same probe the
 *   fallback steps aside on.
 * - **What:** exactly this package's own published `tenant/` files, named
 *   `<Y_m_d_His>_<stem>.php` (spatie/laravel-package-tools' publish name) for each stem the provider's
 *   `hasMigrations()` declares. Never the directory, and never another package's file in it, even one whose
 *   name merely ends in a declared stem.
 * - **Which pass:** the migrator's own paths, which only a central `migrate` reads. A tenant pass runs
 *   `migrate --path=…` (Stancl's `tenancy.migration_parameters`), and an explicit `--path` replaces the
 *   migrator's paths (`BaseCommand::getMigrationPaths()`), so the tenant pass is unchanged.
 * - **When the files are read:** at migration time, not at boot, so a first publish and the `migrate` after
 *   it in the same process see the published files. Two native container hooks share one resolver:
 *   - the migrator's construction, the hook Laravel's own `loadMigrationsFrom()` uses. It covers every
 *     migration command for files already on disk.
 *   - the `migrate` command's construction. Laravel loads that command lazily (`#[AsCommand(name: 'migrate')]`),
 *     so it is built when `migrate` is called: after any publish, `--travel` re-stamp or ownership re-date
 *     earlier in the same process. This hook is needed because on a beam-tenancy host the migrator itself is
 *     NOT lazy. Stancl's `tenants:migrate` declares no `#[AsCommand]` of its own, so Artisan builds it, and
 *     the migrator with it, when Artisan boots, which is before `splicewire:beam:install` publishes anything.
 *
 * Registering a path twice is harmless: `Migrator::path()` keeps its paths unique.
 *
 * **Boundary (not universal rediscovery):** the files are read when the migrator or the `migrate` command is
 * BUILT. If the `migrate` command was already resolved in this process before a later publish, e.g. a second
 * `migrate` after a publish that ran in between, that already-built command does not re-read the directory.
 * The supported sequence is publish (with any re-stamp), then the first `migrate` of the process, which is how
 * `splicewire:beam:install` orders them. Any later run is a new process and reads the files fresh.
 */
trait WiresCentralWorkflowMigrations
{
    /** @param  list<string>  $migrations  the provider's `hasMigrations()` entries (`tenant/<stem>`) */
    protected function wireCentralWorkflowMigrations(array $migrations): void
    {
        if (! class_exists('Splicewire\Beam\Tenancy\BeamTenancyServiceProvider')) {
            return;
        }

        $register = static function (Migrator $migrator, Application $app) use ($migrations): void {
            foreach (self::centralWorkflowMigrationFiles($app->databasePath('migrations'), $migrations) as $file) {
                $migrator->path($file);
            }
        };

        $this->callAfterResolving('migrator', static function (Migrator $migrator, Application $app) use ($register): void {
            $register($migrator, $app);
        });

        $this->callAfterResolving(MigrateCommand::class, static function (MigrateCommand $command, Application $app) use ($register): void {
            $register($app->make('migrator'), $app);
        });
    }

    /**
     * The published files for the declared `tenant/` stems under `$migrationsPath`, sorted. A stem with no
     * published file contributes nothing: before the first publish there is nothing to run.
     *
     * @param  list<string>  $migrations
     * @return list<string>
     */
    public static function centralWorkflowMigrationFiles(string $migrationsPath, array $migrations): array
    {
        $files = [];

        foreach ($migrations as $migration) {
            if (! str_starts_with($migration, 'tenant/')) {
                continue;
            }

            $stem = substr($migration, strlen('tenant/'));
            $published = '/^\d{4}_\d{2}_\d{2}_\d{6}_'.preg_quote($stem, '/').'\.php$/';

            foreach (glob($migrationsPath.'/tenant/*_'.$stem.'.php') ?: [] as $file) {
                if (preg_match($published, basename($file)) === 1) {
                    $files[] = $file;
                }
            }
        }

        sort($files);

        return $files;
    }
}
