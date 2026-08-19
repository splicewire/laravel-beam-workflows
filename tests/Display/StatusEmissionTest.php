<?php

use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Workflows\Display\Events\StatusEmitted;
use Splicewire\Beam\Workflows\Display\Progress;
use Splicewire\Beam\Workflows\Display\State;
use Splicewire\Beam\Workflows\Facades\Status;
use Splicewire\Beam\Workflows\Display\StatusEvent;
use Splicewire\Beam\Workflows\Tests\Fixtures\FakeProcess;

/*
 * Seam A behavioral tests — assert the EXTERNAL behavior a caller can observe (the persisted
 * activity-log row, the fired broadcast event, the untouched artifact), never the internal wiring
 * of activitylog.
 */

it('projects a whole-process status event onto the activity-log timeline', function () {
    $process = FakeProcess::create(['payload' => ['title' => 'my artifact']]);

    Status::running($process, 'generating cells');

    $row = Activity::latest('id')->first();

    expect($row->log_name)->toBe('status')
        ->and($row->description)->toBe('generating cells')
        ->and($row->event)->toBe('running')
        ->and($row->properties['state'])->toBe('running')
        ->and($row->properties['ref'])->toBeNull()
        ->and($row->subject_id)->toBe($process->getKey());
});

it('addresses a sub-part of the process by a stable ref', function () {
    $process = FakeProcess::create();

    Status::complete($process, 'cell rendered', ref: 'cell:lane-1.seg-3');

    $row = Activity::latest('id')->first();

    expect($row->properties['ref'])->toBe('cell:lane-1.seg-3')
        ->and($row->properties['state'])->toBe('complete');
});

it('records determinate progress and omits it when indeterminate', function () {
    $process = FakeProcess::create();

    Status::running($process, 'batch', progress: Progress::of(3, 10));
    $determinate = Activity::latest('id')->first();

    Status::running($process, 'thinking');
    $indeterminate = Activity::latest('id')->first();

    expect($determinate->properties['progress'])->toBe(['done' => 3, 'total' => 10])
        ->and($indeterminate->properties['progress'])->toBeNull();
});

it('groups every event of one run under a shared run id', function () {
    $process = FakeProcess::create();
    $runId = 'run-abc-123';

    Status::queued($process, 'queued', runId: $runId);
    Status::running($process, 'working', runId: $runId);
    Status::complete($process, 'done', runId: $runId);

    // A single foreign event outside the run.
    Status::running($process, 'unrelated');

    $grouped = Activity::query()
        ->where('properties->run_id', $runId)
        ->get();

    expect($grouped)->toHaveCount(3)
        ->and($grouped->pluck('event')->all())->toBe(['queued', 'running', 'complete']);
});

it('fires the broadcast/SSE listener on every emit', function () {
    Event::fake([StatusEmitted::class]);

    $process = FakeProcess::create();
    Status::running($process, 'generating', ref: 'cell:1', runId: 'run-9');

    Event::assertDispatched(StatusEmitted::class, function (StatusEmitted $e) use ($process) {
        return $e->subject->is($process)
            && $e->status->state === State::Running
            && $e->status->ref === 'cell:1'
            && $e->runId === 'run-9';
    });
});

it('broadcasts on the subject-scoped channel with a stable payload', function () {
    $process = FakeProcess::create();
    $event = new StatusEmitted($process, StatusEvent::whole(State::Complete, 'published'), 'run-7', 42);

    $channels = collect($event->broadcastOn())->map->name->all();

    expect($channels)->toBe(['status.'.str_replace('\\', '.', FakeProcess::class).'.'.$process->getKey()])
        ->and($event->broadcastAs())->toBe('status.emitted')
        ->and($event->broadcastWith())->toMatchArray([
            'state' => 'complete',
            'message' => 'published',
            'run_id' => 'run-7',
        ]);
});

it('exposes the broadcast channel name as a server-computable attribute (HasStatusChannel), matching broadcastOn() exactly', function () {
    $process = FakeProcess::create();
    $event = new StatusEmitted($process, StatusEvent::whole(State::Complete, 'published'));

    $broadcastChannel = collect($event->broadcastOn())->first()->name;

    expect(StatusEmitted::channelNameFor($process))->toBe($broadcastChannel)
        ->and($process->status_channel)->toBe($broadcastChannel)
        ->and($process->toArray())->toHaveKey('status_channel', $broadcastChannel);
});

it('never writes status into the artifact it is generating (the load-bearing invariant)', function () {
    $process = FakeProcess::create(['payload' => ['title' => 'clean']]);

    Status::running($process, 'generating');
    Status::complete($process, 'done');

    $process->refresh();

    // The artifact's own data is structurally untouched — status lives only on the timeline.
    expect($process->payload)->toBe(['title' => 'clean'])
        ->and(array_keys($process->getAttributes()))->not->toContain('state', 'status');
});
