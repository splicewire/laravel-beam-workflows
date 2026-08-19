<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Workflows\Facades\Status;
use Splicewire\Beam\Workflows\Tests\Fixtures\AltActivity;
use Splicewire\Beam\Workflows\Tests\Fixtures\FakeProcess;

/*
 * Seam A, tenancy-awareness: a host whose subject lives on a different connection/store than the
 * default `activity_log` (e.g. a central-connection audit model, so a central subject's status is
 * readable where the host reads it) maps that subject-class to its own Activity model. The emitter
 * projects a mapped subject's status into the mapped model; everything else stays on the default.
 */

beforeEach(function () {
    if (! Schema::hasTable('alt_activity_log')) {
        Schema::create('alt_activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'alt_subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'alt_causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
});

it('routes a mapped subject status into the configured activity model', function () {
    config(['beam-workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    $process = FakeProcess::create();
    Status::running($process, 'routed', ref: 'r:1', runId: 'run-1');

    // Landed in the mapped model's store, with the same normalized shape...
    $row = AltActivity::latest('id')->first();
    expect($row)->not->toBeNull()
        ->and($row->log_name)->toBe('status')
        ->and($row->description)->toBe('routed')
        ->and($row->event)->toBe('running')
        ->and($row->properties['ref'])->toBe('r:1')
        ->and($row->properties['run_id'])->toBe('run-1')
        ->and($row->subject_id)->toBe($process->getKey());

    // ...and NOT in the default activity_log.
    expect(Activity::count())->toBe(0);
});

it('falls back to the default activity model for unmapped subjects', function () {
    config(['beam-workflows.activity_models' => ['App\\Nonexistent\\Other' => AltActivity::class]]);

    $process = FakeProcess::create();
    Status::complete($process, 'default path');

    expect(Activity::latest('id')->first()?->description)->toBe('default path')
        ->and(AltActivity::count())->toBe(0);
});

it('honors a global default activity model when no per-subject map matches', function () {
    config(['beam-workflows.activity_model' => AltActivity::class]);

    $process = FakeProcess::create();
    Status::queued($process, 'global default');

    expect(AltActivity::latest('id')->first()?->description)->toBe('global default')
        ->and(Activity::count())->toBe(0);
});

it('restores the ambient activity model after emitting (no leak across emits)', function () {
    config(['beam-workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    $process = FakeProcess::create();
    Status::running($process, 'mapped');

    // The ambient spatie config is untouched by the scoped swap.
    expect(config('activitylog.activity_model'))->not->toBe(AltActivity::class);
});
