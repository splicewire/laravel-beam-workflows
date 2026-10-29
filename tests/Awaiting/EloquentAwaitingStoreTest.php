<?php

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\EloquentAwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;

/*
 * The default store over the table the shipped stub creates (TestCase builds it from the stub), for every
 * key type a host's subject can have. A host's models are not ours to choose: the beam starter's
 * ServiceRequest has integer ids, a Composition has UUIDs, others use ULIDs. sqlite stores any of them in
 * any column, so the integer case only bites on a typed driver — run this file on Postgres too
 * (PG_TEST_DATABASE, see TestCase) to see it.
 */

class AwaitingIntegerSubject extends Model
{
    protected $table = 'awaiting_integer_subjects';

    protected $guarded = [];

    public $timestamps = false;
}

class AwaitingUuidSubject extends Model
{
    use HasUuids;

    protected $table = 'awaiting_uuid_subjects';

    protected $guarded = [];

    public $timestamps = false;
}

class AwaitingUlidSubject extends Model
{
    use HasUlids;

    protected $table = 'awaiting_ulid_subjects';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('awaiting_integer_subjects', function (TableBlueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('awaiting_uuid_subjects', function (TableBlueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
    });
    Schema::create('awaiting_ulid_subjects', function (TableBlueprint $table) {
        $table->ulid('id')->primary();
        $table->string('name');
    });
});

dataset('subject key types', [
    'integer' => [AwaitingIntegerSubject::class],
    'uuid' => [AwaitingUuidSubject::class],
    'ulid' => [AwaitingUlidSubject::class],
]);

it('is the default store the package binds', function () {
    expect(app(AwaitingStore::class))->toBeInstanceOf(EloquentAwaitingStore::class);
});

it('stamps, reads back and clears a subject of any key type', function (string $model) {
    $store = app(AwaitingStore::class);
    $subject = $model::create(['name' => 'first']);
    $other = $model::create(['name' => 'second']);

    $store->stamp($subject, 'review', 'role:reviewer');
    $store->stamp($subject, 'review', 'owner:');
    $store->stamp($subject, 'approve', 'role:approver');
    $store->stamp($other, 'review', 'role:reviewer');

    // Read: the row carries the subject's own key, and the morph resolves back to that subject.
    $row = WorkflowAwaiting::query()
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('principal', 'role:reviewer')
        ->sole();
    expect((string) $row->subject_id)->toBe((string) $subject->getKey())
        ->and($row->subject)->toBeInstanceOf($model)
        ->and($row->subject->is($subject))->toBeTrue();

    // Clear by place: only this subject's `review` rows go.
    $store->clearForPlaces($subject, ['review']);
    expect(WorkflowAwaiting::query()->where('subject_id', $subject->getKey())->pluck('place')->all())->toBe(['approve'])
        ->and(WorkflowAwaiting::query()->where('subject_id', $other->getKey())->count())->toBe(1);

    // Clear by subject: the rest of this subject's rows go, the other subject's stay.
    $store->clearForSubject($subject);
    expect(WorkflowAwaiting::query()->where('subject_id', $subject->getKey())->count())->toBe(0)
        ->and(WorkflowAwaiting::query()->where('subject_id', $other->getKey())->count())->toBe(1);
})->with('subject key types');

it('keeps a rollup parent of any key type', function (string $model) {
    $parent = $model::create(['name' => 'parent']);
    $subject = AwaitingIntegerSubject::create(['name' => 'child']);

    app(AwaitingStore::class)->stamp($subject, 'review', 'role:reviewer', $parent->getMorphClass(), (string) $parent->getKey());

    $row = WorkflowAwaiting::query()->sole();
    expect((string) $row->parent_id)->toBe((string) $parent->getKey())
        ->and($row->parent)->toBeInstanceOf($model)
        ->and($row->parent->is($parent))->toBeTrue();
})->with('subject key types');

it('clears an integer subject inside a transaction without aborting it', function () {
    // The measured failure (ttc build 33): on Postgres a uuid `subject_id` refuses the integer in the
    // clear's WHERE, the transition swallows the error, and the enclosing transaction is left aborted —
    // every later statement in it fails. Here the later statement must succeed and the commit land.
    $subject = AwaitingIntegerSubject::create(['name' => 'in-a-transaction']);
    app(AwaitingStore::class)->stamp($subject, 'review', 'role:reviewer');

    DB::transaction(function () use ($subject) {
        app(AwaitingStore::class)->clearForPlaces($subject, ['review']);
        $subject->update(['name' => 'still-writable']);
    });

    expect($subject->fresh()->name)->toBe('still-writable')
        ->and(WorkflowAwaiting::query()->count())->toBe(0);
});
