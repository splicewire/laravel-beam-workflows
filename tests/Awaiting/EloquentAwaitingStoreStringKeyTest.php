<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\WorkflowAwaiting;

/*
 * The store writes and matches `(string) $subject->getKey()` (make-surface 01, owner ruling 01b §1), the
 * form LifecycleService and WorkflowReactionService already write into the string morph columns. Reading
 * the row back cannot show it: a varchar column returns text on every driver, whatever was bound. So this
 * pins the value the store BINDS, for an integer key, on the insert and on both clears.
 */

class AwaitingStringKeySubject extends Model
{
    protected $table = 'awaiting_string_key_subjects';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::create('awaiting_string_key_subjects', function (TableBlueprint $table) {
        $table->id();
        $table->string('name');
    });
});

it('binds an integer subject key as its string form when it stamps and when it clears', function () {
    $subject = AwaitingStringKeySubject::create(['name' => 'integer-keyed']);
    $other = AwaitingStringKeySubject::create(['name' => 'kept']);
    $key = $subject->getKey();
    expect($key)->toBeInt();

    $store = app(AwaitingStore::class);
    $store->stamp($other, 'review', 'role:reviewer');

    $bound = [];
    DB::listen(function (QueryExecuted $query) use (&$bound) {
        if (str_contains($query->sql, 'workflow_awaitings')) {
            $bound[] = $query->bindings;
        }
    });

    $store->stamp($subject, 'review', 'role:reviewer');
    $store->stamp($subject, 'approve', 'role:approver');
    $store->clearForPlaces($subject, ['review']);

    // The match: the place clear took only this subject's `review` row.
    expect(WorkflowAwaiting::query()->where('subject_id', (string) $key)->pluck('place')->all())->toBe(['approve']);

    $store->clearForSubject($subject);

    // stamp ×2, clearForPlaces, the read above, clearForSubject: every statement binds the string key, never the int.
    expect($bound)->toHaveCount(5);
    foreach ($bound as $bindings) {
        expect($bindings)->toContain((string) $key)->not->toContain($key);
    }

    // And the subject clear matched every remaining row of this subject, and none of the other's.
    expect(WorkflowAwaiting::query()->where('subject_id', (string) $key)->count())->toBe(0)
        ->and(WorkflowAwaiting::query()->where('subject_id', (string) $other->getKey())->count())->toBe(1);
});
