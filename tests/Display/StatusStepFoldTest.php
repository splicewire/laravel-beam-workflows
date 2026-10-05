<?php

use Splicewire\Beam\Workflows\Data\StatusEventData;
use Splicewire\Beam\Workflows\Data\StatusStepData;

/*
 * app-walkthrough APP-06 (APP-10, FINDINGS 15): a status timeline renders per-STEP state folded from the event log,
 * never the raw log's last word per step. A step is done once a later step starts or the run completes; the open step
 * at a failure is failed; only a step still in flight is running. The newest run is folded; older runs are History.
 */

function stepFoldEvent(string $state, ?string $ref = null, string $run = 'r2', ?string $message = null): StatusEventData
{
    return new StatusEventData(id: uniqid(), state: $state, message: $message ?? $ref, ref: $ref, runId: $run);
}

function stepFoldStates(array $events): array
{
    return array_map(fn (StatusStepData $s) => [$s->ref, $s->state], StatusStepData::fold($events));
}

it('leaves no step running after the run completes', function () {
    expect(stepFoldStates([
        stepFoldEvent('running', 'database'), stepFoldEvent('running', 'migrations'), stepFoldEvent('running', 'seed'), stepFoldEvent('complete'),
    ]))->toBe([['database', 'done'], ['migrations', 'done'], ['seed', 'done']]);
});

it('closes a step when the next one starts, and keeps only the newest step running mid-run', function () {
    expect(stepFoldStates([stepFoldEvent('running', 'database'), stepFoldEvent('running', 'migrations')]))
        ->toBe([['database', 'done'], ['migrations', 'running']]);
});

it('marks the open step failed when the run fails', function () {
    expect(stepFoldStates([stepFoldEvent('running', 'database'), stepFoldEvent('running', 'migrations'), stepFoldEvent('failed', message: 'boom')]))
        ->toBe([['database', 'done'], ['migrations', 'failed']]);
});

it('folds only the newest run; an earlier failed run is History', function () {
    expect(stepFoldStates([
        stepFoldEvent('running', 'database', 'r1'), stepFoldEvent('failed', run: 'r1'),
        stepFoldEvent('running', 'database', 'r2'), stepFoldEvent('complete', run: 'r2'),
    ]))->toBe([['database', 'done']]);
});

it('folds an empty timeline to no steps', function () {
    expect(StatusStepData::fold([]))->toBe([]);
});
