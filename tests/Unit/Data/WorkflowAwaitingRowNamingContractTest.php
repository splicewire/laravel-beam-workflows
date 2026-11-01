<?php

namespace Splicewire\Beam\Workflows\Tests\Unit\Data;

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Workflows\Data\WorkflowAwaitingRowData;

final class WorkflowAwaitingRowNamingContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/src/Data/WorkflowAwaitingRowData.php';
        require_once dirname(__DIR__, 3).'/src/Awaiting/WorkflowAwaiting.php';

        $container = new Application('/tmp/dto-c-beam-workflows-test-app');
        Application::setInstance($container);
        $container->instance('config', new Repository([
            'data' => require dirname((new \ReflectionClass(\Spatie\LaravelData\Data::class))->getFileName(), 2).'/config/data.php',
        ]));

        $database = new Capsule($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->setAsGlobal();
        $database->bootEloquent();
    }

    public function test_camel_properties_preserve_snake_case_wire_keys(): void
    {
        self::assertStringStartsWith(
            dirname(__DIR__, 3).'/src/',
            (new \ReflectionClass(WorkflowAwaitingRowData::class))->getFileName(),
        );

        $row = WorkflowAwaitingRowData::from([
            'id' => 'row-1',
            'subject_type' => 'composition',
            'subject_id' => 'subject-1',
            'place' => 'review',
            'principal' => 'role:reviewer',
            'parent_type' => 'tenant',
            'parent_id' => 'parent-1',
            'created_at' => '2026-11-01T00:00:00+00:00',
        ]);

        self::assertSame('composition', $row->subjectType);
        self::assertSame('subject-1', $row->subjectId);
        self::assertSame('tenant', $row->parentType);
        self::assertSame('parent-1', $row->parentId);
        self::assertSame('2026-11-01T00:00:00+00:00', $row->createdAt);
        self::assertSame([
            'id' => 'row-1',
            'subject_type' => 'composition',
            'subject_id' => 'subject-1',
            'place' => 'review',
            'principal' => 'role:reviewer',
            'parent_type' => 'tenant',
            'parent_id' => 'parent-1',
            'created_at' => '2026-11-01T00:00:00+00:00',
        ], $row->toArray());

        foreach (['subject_type', 'subject_id', 'parent_type', 'parent_id', 'created_at'] as $legacyName) {
            self::assertFalse(property_exists($row, $legacyName), "Legacy property {$legacyName} should be removed.");
        }
    }

    public function test_model_columns_still_hydrate_camel_properties(): void
    {
        $model = new \Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;
        $model->setRawAttributes([
            'id' => 'row-2',
            'subject_type' => 'composition',
            'subject_id' => 'subject-2',
            'place' => 'review',
            'principal' => 'role:reviewer',
            'parent_type' => 'tenant',
            'parent_id' => 'parent-2',
            'created_at' => '2026-11-01 00:00:00',
        ]);

        $row = WorkflowAwaitingRowData::from($model);

        self::assertSame('composition', $row->subjectType);
        self::assertSame('subject-2', $row->subjectId);
        self::assertSame('tenant', $row->parentType);
        self::assertSame('parent-2', $row->parentId);
        self::assertSame([
            'id' => 'row-2',
            'subject_type' => 'composition',
            'subject_id' => 'subject-2',
            'place' => 'review',
            'principal' => 'role:reviewer',
            'parent_type' => 'tenant',
            'parent_id' => 'parent-2',
            'created_at' => '2026-11-01 00:00:00',
        ], $row->toArray());
    }
}
