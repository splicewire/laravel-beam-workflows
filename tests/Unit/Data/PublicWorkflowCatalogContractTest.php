<?php

namespace Splicewire\Beam\Workflows\Tests\Unit\Data;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\FortifyServiceProvider;
use Orchestra\Testbench\TestCase;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Sluggable\SluggableServiceProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\RoleOptionData;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Codegen\BeamTypeScriptTransformerExtension;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\DeclaredRouteTypes;
use Splicewire\Beam\Codegen\PackageTypeRoots;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;
use Splicewire\Beam\Workflows\Data\GuardCatalogEntryData;
use Splicewire\Beam\Workflows\Data\PrincipalKindData;
use Splicewire\Beam\Workflows\Data\WorkflowCatalogData;

class PublicWorkflowCatalogContractTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PopcornServiceProvider::class,
            LaravelDataServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            FortifyServiceProvider::class,
            SluggableServiceProvider::class,
            BeamServiceProvider::class,
            BeamAccountsServiceProvider::class,
            ActivitylogServiceProvider::class,
            BeamWorkflowsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    public function test_accounts_provider_boots_with_its_existing_defaults_without_tower(): void
    {
        $this->assertFalse(class_exists('Splicewire\\Tower\\Api\\V1\\WorkflowController'));
        $this->assertTrue($this->app->getLoadedProviders()[BeamAccountsServiceProvider::class]);
        $this->assertTrue((bool) config('beam.accounts.register_routes', true));
        $this->assertTrue(Route::has('profile.edit'));
        $this->assertFalse(Schema::hasTable('users'), 'Accounts migrations remain publish-only.');
    }

    public function test_the_public_catalog_projects_nested_roles_without_tower(): void
    {
        $this->assertFalse(class_exists('Splicewire\\Tower\\Data\\PrincipalKindData'));
        $catalog = new WorkflowCatalogData([], [new GuardCatalogEntryData('approval', 'Approval', [])], [], [], [
            new PrincipalKindData('owner', 'Record owner'),
            new PrincipalKindData('role', 'By role', [new RoleOptionData('admin', 'Admin')]),
        ]);
        $this->assertSame(null, $catalog->toArray()['principals'][0]['options']);
        $this->assertSame([['value' => 'admin', 'label' => 'Admin']], $catalog->toArray()['principals'][1]['options']);
        $schema = WorkflowCatalogData::jsonSchema();
        $this->assertSame('array', $schema['properties']['principals']['type']);
        $this->assertSame([], $schema['properties']['principals']['default']);
        $roleSchema = app(JsonSchemaGenerator::class)->generate(new \ReflectionClass(RoleOptionData::class));
        $this->assertSame(['value', 'label'], array_keys($roleSchema['properties']));
        $this->assertSame(['value', 'label'], $roleSchema['required']);

        $directory = sys_get_temp_dir().'/public-workflow-types-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);
        try {
            $router = new Router(new Dispatcher($this->app), $this->app);
            $router->get('/catalog', [PublicWorkflowCatalogProbeController::class, '__invoke']);
            $config = TypeScriptTransformerConfigFactory::create()
                ->outputDirectory($directory)
                ->extension(new BeamTypeScriptTransformerExtension(
                    new DeclaredParticleTypes(new ParticleResourceRegistry, new ParticleOperationRegistry, new ResourceContributionRegistry),
                    new PackageTypeRoots([]),
                    new DeclaredRouteTypes($router, app(RouteReturnType::class)),
                ))
                ->writer(new GlobalNamespaceWriter('public-workflows.d.ts'))
                ->get();
            TypeScriptTransformer::create($config)->execute();
            $typescript = file_get_contents($directory.'/public-workflows.d.ts');
            foreach (['WorkflowCatalogData', 'GuardCatalogEntryData', 'PrincipalKindData', 'RoleOptionData'] as $name) {
                $this->assertStringContainsString('export type '.$name.' =', $typescript);
            }
            $this->assertStringNotContainsString('Splicewire.Tower', $typescript);
            $this->assertStringNotContainsString('undefined', $typescript);
            $this->assertStringContainsString('Splicewire.Beam.Accounts.Data.RoleOptionData', $typescript);
        } finally {
            $files->deleteDirectory($directory);
        }
    }
}

class PublicWorkflowCatalogProbeController
{
    #[ResponseFromData(WorkflowCatalogData::class)]
    public function __invoke(): void {}
}
