<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;

/*
 * The upgrade path for a host that already ran the UUID-morph `create_workflow_awaitings_table` (every
 * host that published before the stub declared string morph ids). Editing the create stub reaches only a
 * fresh database, and the convergent guard cannot reach an existing one: a column present with the wrong
 * type is its tier-three CONFLICT, not something it converges (proved below on Postgres). So the package
 * ships a separately stamped ALTER, `widen_workflow_awaitings_morph_ids_to_string`, after the create.
 */

class WidenedAwaitingSubject extends Model
{
    protected $table = 'widened_awaiting_subjects';

    protected $guarded = [];

    public $timestamps = false;
}

function awaitingsStub(string $name): object
{
    return require dirname(__DIR__, 2).'/database/migrations/tenant/'.$name.'.php.stub';
}

/** The table exactly as `create_workflow_awaitings_table.php.stub` declared it up to 0ea706d (UUID morphs). */
function createPublishedUuidAwaitingsTable(): void
{
    Schema::create('workflow_awaitings', function (TableBlueprint $table) {
        $table->uuid('id')->primary();
        $table->uuidMorphs('subject');
        $table->string('place');
        $table->string('principal');
        $table->nullableUuidMorphs('parent');
        $table->timestamp('created_at')->nullable();
        $table->unique(['subject_type', 'subject_id', 'place', 'principal'], 'workflow_awaitings_natural_key');
        $table->index('principal', 'workflow_awaitings_principal_index');
    });
}

/** @return array<string, array{type_name: string, nullable: bool}> */
function awaitingMorphColumns(): array
{
    return collect(Schema::getColumns('workflow_awaitings'))
        ->filter(fn (array $c) => in_array($c['name'], ['subject_type', 'subject_id', 'parent_type', 'parent_id'], true))
        ->mapWithKeys(fn (array $c) => [$c['name'] => ['type_name' => strtolower($c['type_name']), 'nullable' => (bool) $c['nullable']]])
        ->sortKeys()
        ->all();
}

/** @return list<string> */
function awaitingIndexNames(): array
{
    return collect(Schema::getIndexes('workflow_awaitings'))->pluck('name')->map(fn ($n) => strtolower($n))->sort()->values()->all();
}

beforeEach(function () {
    Schema::create('widened_awaiting_subjects', function (TableBlueprint $table) {
        $table->id();
        $table->string('name');
    });

    // TestCase builds the table from the current create stub; these tests need a host's older one.
    Schema::dropIfExists('workflow_awaitings');
});

it('widens an already-migrated host\'s UUID morph ids to strings, so an integer subject works', function () {
    createPublishedUuidAwaitingsTable();
    $indexesBefore = awaitingIndexNames();
    $subject = WidenedAwaitingSubject::create(['name' => 'integer-keyed']);

    if (Schema::getConnection()->getDriverName() === 'pgsql') {
        // The host's failure, before the upgrade: Postgres refuses an integer in a uuid column.
        expect(fn () => app(AwaitingStore::class)->stamp($subject, 'review', 'role:reviewer'))
            ->toThrow(QueryException::class, 'invalid input syntax for type uuid');
    }

    awaitingsStub('widen_workflow_awaitings_morph_ids_to_string')->up();

    $columns = awaitingMorphColumns();
    foreach (['subject_id', 'parent_id'] as $column) {
        expect($columns[$column]['type_name'])->toBeIn(['varchar', 'character varying', 'nvarchar']);
    }
    expect($columns['subject_id']['nullable'])->toBeFalse()
        ->and($columns['parent_id']['nullable'])->toBeTrue()
        ->and(awaitingIndexNames())->toBe($indexesBefore);

    $store = app(AwaitingStore::class);
    $store->stamp($subject, 'review', 'role:reviewer', $subject->getMorphClass(), '42');
    $store->stamp($subject, 'review', 'role:reviewer'); // the natural key still de-duplicates
    expect(WorkflowAwaiting::query()->sole()->subject->is($subject))->toBeTrue();

    $store->clearForPlaces($subject, ['review']);
    expect(WorkflowAwaiting::query()->count())->toBe(0);

    // The widened table IS the current create stub's shape: rehearsing that stub over it changes nothing.
    $reports = ConvergentTable::rehearse(fn () => awaitingsStub('create_workflow_awaitings_table')->up());
    expect($reports)->toHaveCount(1)
        ->and($reports[0]->unchanged())->toBeTrue();
});

it('is a no-op on a table the current create stub made, and safe to run twice', function () {
    awaitingsStub('create_workflow_awaitings_table')->up();
    $columns = awaitingMorphColumns();
    $indexes = awaitingIndexNames();

    awaitingsStub('widen_workflow_awaitings_morph_ids_to_string')->up();
    awaitingsStub('widen_workflow_awaitings_morph_ids_to_string')->up();

    expect(awaitingMorphColumns())->toBe($columns)
        ->and(awaitingIndexNames())->toBe($indexes);
});

it('is a no-op when the host has no awaitings table yet', function () {
    awaitingsStub('widen_workflow_awaitings_morph_ids_to_string')->up();

    expect(Schema::hasTable('workflow_awaitings'))->toBeFalse();
});

it('is needed because the convergent create cannot change an existing column\'s type', function () {
    // On Postgres the old table's uuid `subject_id` is a tier-three conflict for the current stub's
    // string declaration — the guard reports it and writes nothing. (On sqlite both compile to varchar,
    // so there is nothing to see and nothing for the ALTER to do.)
    if (Schema::getConnection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('A typed driver only: sqlite compiles uuid and string alike.');
    }

    createPublishedUuidAwaitingsTable();
    $reports = ConvergentTable::rehearse(fn () => awaitingsStub('create_workflow_awaitings_table')->up());

    expect($reports)->toHaveCount(1)
        ->and(collect($reports[0]->conflicts)->map(fn ($c) => "{$c->kind}:{$c->column}")->sort()->values()->all())
        ->toBe(['type:parent_id', 'type:subject_id']);
});
