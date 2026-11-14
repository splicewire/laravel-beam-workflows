<?php

namespace Splicewire\Beam\Workflows\Tests\Localization;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Splicewire\Beam\Workflows\History\WorkflowHistoryResources;
use Splicewire\Beam\Workflows\Tests\TestCase;

// Pest boots Composer without defining PHPUnit's isolated-process autoload path. PHPUnit
// needs this before its XML bootstrap when PreserveGlobalState(false) omits copied includes.
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__, 2).'/vendor/autoload.php');
}

/** Proves Calendars classes are unavailable to the autoloader, not Composer-uninstalled. */
class WorkflowWithoutCalendarsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof ClassLoader) {
                spl_autoload_unregister($loader);
                spl_autoload_register(static function (string $class) use ($loader): void {
                    if (! str_starts_with($class, 'Splicewire\\Beam\\Calendars\\')) {
                        $loader($class);
                    }
                });
            }
        }

        return parent::getPackageProviders($app);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_provider_and_nonreaction_request_boot_without_optional_calendar_classes(): void
    {
        self::assertTrue(class_exists(\PHPUnit\TextUI\Configuration\Registry::class));
        self::assertSame(realpath(dirname(__DIR__, 2).'/vendor/autoload.php'), realpath(PHPUNIT_COMPOSER_INSTALL));
        self::assertContains(realpath(PHPUNIT_COMPOSER_INSTALL), array_map('realpath', get_included_files()), 'The isolated child must load the normal root vendor/autoload.php.');
        self::assertFalse(class_exists('Splicewire\\Beam\\Calendars\\Registries\\ActionHandlerRegistry'));
        self::assertNotContains('Splicewire\\Beam\\Workflows\\Reactions\\WorkflowActionConflict', get_declared_classes());
        config(['app.debug' => false]);
        WorkflowHistoryResources::mount('without-calendars-history');
        $response = $this->postJson('without-calendars-history/read', ['subject_kind' => 'absent', 'subject_id' => '1', 'limit' => 101])
            ->assertStatus(422);
        if (($receipt = getenv('WORKFLOW_CHILD_AUTOLOAD_RECEIPT')) !== false && $receipt !== '') {
            self::assertNotFalse(file_put_contents($receipt, json_encode([
                'normalVendorAutoloadIncluded' => in_array(realpath(PHPUNIT_COMPOSER_INSTALL), array_map('realpath', get_included_files()), true),
                'registryAvailable' => class_exists(\PHPUnit\TextUI\Configuration\Registry::class),
                'calendarsRegistryUnavailable' => ! class_exists('Splicewire\\Beam\\Calendars\\Registries\\ActionHandlerRegistry'),
                'mountedHistoryStatus' => $response->status(),
            ], JSON_THROW_ON_ERROR).PHP_EOL));
        }
        $response->assertJsonPath('codes.limit.0', 'workflow.history_page_size');
        self::assertNotContains('Splicewire\\Beam\\Workflows\\Reactions\\WorkflowActionConflict', get_declared_classes());
        self::assertFalse(class_exists('Splicewire\\Beam\\Calendars\\Actions\\ActionConflict', false));
    }
}
