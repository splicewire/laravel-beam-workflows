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

/*
 * Owner re-walk RW-32 (2026-10-19): the operator's tenant page read "Creating subscription — Done" beside "No
 * subscription yet". A step that records it had nothing to do (a `skipped` event on its ref) folds to `skipped`, with
 * the event's message as its reason, and stays skipped when the run moves on, completes or fails later.
 */
it('folds a step that recorded nothing to do as skipped, with its reason, through a later step and the run completing', function () {
    $steps = StatusStepData::fold([
        stepFoldEvent('running', 'subscription', message: 'Creating subscription'),
        stepFoldEvent('skipped', 'subscription', message: 'no plan selected'),
        stepFoldEvent('running', 'packs', message: 'Applying scaffold packs'),
        stepFoldEvent('complete'),
    ]);

    expect(array_map(fn (StatusStepData $s) => [$s->ref, $s->label, $s->state, $s->reason], $steps))->toBe([
        ['subscription', 'Creating subscription', 'skipped', 'no plan selected'],
        ['packs', 'Applying scaffold packs', 'done', null],
    ]);
});

it('keeps a skipped step skipped when a later step fails, and folds a skip with no start under its ref', function () {
    expect(stepFoldStates([
        stepFoldEvent('skipped', 'subscription', message: 'billing rolls up to the reseller'),
        stepFoldEvent('running', 'packs'),
        stepFoldEvent('failed', message: 'boom'),
    ]))->toBe([['subscription', 'skipped'], ['packs', 'failed']]);
});

it('reads skips from the newest run only', function () {
    expect(stepFoldStates([
        stepFoldEvent('running', 'subscription', 'r1'), stepFoldEvent('skipped', 'subscription', 'r1'), stepFoldEvent('failed', run: 'r1'),
        stepFoldEvent('running', 'subscription', 'r2'), stepFoldEvent('complete', run: 'r2'),
    ]))->toBe([['subscription', 'done']]);
});

/*
 * A step that skipped and then starts again in the same run (a retry) is running, and later done: the reason it gave for
 * doing nothing is about the attempt that skipped, so it goes when the step restarts rather than riding along on a step
 * that is doing something.
 */
it('clears a skipped step\'s reason when the step starts again, through running and done', function () {
    $restarted = [
        stepFoldEvent('running', 'subscription', message: 'Creating subscription'),
        stepFoldEvent('skipped', 'subscription', message: 'no plan selected'),
        stepFoldEvent('running', 'subscription', message: 'Creating subscription'),
    ];
    $row = fn (array $events) => array_map(fn (StatusStepData $s) => [$s->ref, $s->state, $s->reason], StatusStepData::fold($events));

    expect($row($restarted))->toBe([['subscription', 'running', null]])
        ->and($row([...$restarted, stepFoldEvent('complete')]))->toBe([['subscription', 'done', null]]);
});

it('keeps the reason of a step that skipped a second time', function () {
    expect(array_map(fn (StatusStepData $s) => [$s->state, $s->reason], StatusStepData::fold([
        stepFoldEvent('running', 'subscription', message: 'Creating subscription'),
        stepFoldEvent('skipped', 'subscription', message: 'no plan selected'),
        stepFoldEvent('running', 'subscription', message: 'Creating subscription'),
        stepFoldEvent('skipped', 'subscription', message: 'the plan is not in the catalog'),
    ])))->toBe([['skipped', 'the plan is not in the catalog']]);
});
