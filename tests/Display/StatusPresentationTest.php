<?php

use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Data\MessageReferenceData;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Workflows\Control\MarkingSubject;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRunner;
use Splicewire\Beam\Workflows\Data\StatusEventData;
use Splicewire\Beam\Workflows\Data\StatusStepData;
use Splicewire\Beam\Workflows\Display\Events\StatusEmitted;
use Splicewire\Beam\Workflows\Display\State;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusEvent;
use Splicewire\Beam\Workflows\Facades\Status;
use Splicewire\Beam\Workflows\Tests\Fixtures\FakeProcess;

/*
 * localization 37 (RULING-04 M4, M5, M6, M11, §5; SIGNATURES-37b): a saved status row renders for its reader. A NEW
 * write's stored reference renders in the reader's language; a bare-state row shows the state word; any other legacy
 * row stays literal. The broadcast payload is byte-identical, and the raw diagnostic is never projected.
 */

beforeEach(function (): void {
    // A Spanish host override for workflows' keys (lang/vendor/<namespace>/<locale>/<group>.php).
    $this->lang = sys_get_temp_dir().'/wf-status-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/vendor/beam-workflows/es', 0777, true);
    file_put_contents($this->lang.'/vendor/beam-workflows/es/status.php', '<?php return '.var_export([
        'transition' => 'transición :transition → :places',
        'migrated' => 'migrado: :old → :new (versión fijada en :version)',
        'state' => ['running' => 'en curso', 'complete' => 'completo', 'failed' => 'fallido', 'skipped' => 'omitido', 'queued' => 'en cola'],
    ], true).';');
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config(['beam.localization.supported' => ['en', 'es'], 'app.locale' => 'en']);
    app()->setLocale('en');
});

function transitionReference(): MessageReferenceData
{
    return MessageReferenceData::of('beam-workflows::status.transition', 'transition::transition → :places', ['transition' => 'publish', 'places' => 'published']);
}

it('renders one saved reference row for two readers, everything else byte-identical (M4, M11)', function (): void {
    $process = FakeProcess::create();
    app(StatusEmitter::class)->emit($process, StatusEvent::whole(State::Complete, 'transition:publish → published')->referencing(transitionReference()), 'run-1', 'user:ed');
    $row = Activity::latest('id')->first();
    $stored = [$row->description, json_encode($row->properties)];

    $en = StatusEventData::fromActivity($row);
    $es = LocaleScope::run('es', fn () => StatusEventData::fromActivity($row));

    expect($en->message)->toBe('transition:publish → published', 'English is byte-identical to the old literal')
        ->and($es->message)->toBe('transición publish → published')
        ->and([$es->id, $es->state, $es->ref, $es->runId, $es->at])->toBe([$en->id, $en->state, $en->ref, $en->runId, $en->at])
        ->and([$row->fresh()->description, json_encode($row->fresh()->properties)])->toBe($stored);
});

it('folds the reader\'s language into step labels and reasons, keeping the same shape (M11)', function (): void {
    $process = FakeProcess::create();
    app(StatusEmitter::class)->emit($process, StatusEvent::for('step-a', State::Running, 'transition:publish → published')->referencing(transitionReference()));
    app(StatusEmitter::class)->emit($process, StatusEvent::for('step-b', State::Skipped, 'transition:publish → published')->referencing(transitionReference()));
    $rows = Activity::query()->oldest('id')->get();

    $steps = LocaleScope::run('es', fn () => StatusStepData::fold($rows->map(fn ($row) => StatusEventData::fromActivity($row))->all()));
    $byRef = collect($steps)->keyBy('ref');

    expect($byRef['step-a']->label)->toBe('transición publish → published')
        ->and($byRef['step-b']->reason)->toBe('transición publish → published');
});

it('shows a bare-state row as the state word, and any other legacy row literally (legacy, not translated)', function (): void {
    $process = FakeProcess::create();
    Status::running($process);
    $bare = Activity::latest('id')->first();
    Status::running($process, 'generating cells');
    $legacy = Activity::latest('id')->first();

    expect($bare->description)->toBe('running')
        ->and(LocaleScope::run('es', fn () => StatusEventData::fromActivity($bare)->message))->toBe('en curso')
        ->and(LocaleScope::run('es', fn () => StatusEventData::fromActivity($legacy)->message))->toBe('generating cells');
});

it('stores the M6 transition reference from the runner, its English description unchanged', function (): void {
    $registry = app(WorkflowRegistry::class);
    $registry->register('presentation.m6', ['name' => 'presentation.m6', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']]]);
    $subject = MarkingSubject::fromPlaces(['published']);
    $process = FakeProcess::create();

    app(WorkflowRunner::class)->emitStatus($registry->get('presentation.m6'), $subject, 'publish', $process, new TransitionContext(runId: 'run-m6'));
    $row = Activity::latest('id')->first();

    expect($row->description)->toBe('transition:publish → published')
        ->and($row->properties['messageReference']['key'])->toBe('beam-workflows::status.transition')
        ->and($row->properties['messageReference']['parameters'])->toBe(['transition' => 'publish', 'places' => 'published'])
        ->and(LocaleScope::run('es', fn () => StatusEventData::fromActivity($row)->message))->toBe('transición publish → published');
});

it('keeps an event without a reference byte-identical in storage', function (): void {
    expect(StatusEvent::whole(State::Running, 'x')->properties())->toBe(['ref' => null, 'state' => 'running', 'progress' => null]);
});

it('broadcasts a byte-identical payload with or without a reference (M5 stays transport)', function (): void {
    $process = FakeProcess::create();
    $at = now()->startOfSecond();
    // The event's real constructor types: runId ?string, activityId ?int, actor ?string.
    $plain = new StatusEmitted($process, StatusEvent::whole(State::Complete, 'transition:publish → published', at: $at), runId: 'run-7', activityId: 41, actor: 'user:ed');
    $referenced = new StatusEmitted($process, StatusEvent::whole(State::Complete, 'transition:publish → published', at: $at)->referencing(transitionReference(), 'raw boom'), runId: 'run-7', activityId: 41, actor: 'user:ed');

    expect(json_encode($referenced->broadcastWith()))->toBe(json_encode($plain->broadcastWith()))
        ->and(json_encode($referenced->broadcastWith()))->not->toContain('messageReference')->not->toContain('raw boom')
        ->and($referenced->broadcastAs())->toBe('status.emitted');
});

it('stores a raw diagnostic apart and never projects it', function (): void {
    $process = FakeProcess::create();
    Event::fake([StatusEmitted::class]);
    app(StatusEmitter::class)->emit($process, StatusEvent::whole(State::Failed, 'transition:publish → published')->referencing(transitionReference(), 'SQLSTATE[08006] raw boom'));
    $row = Activity::latest('id')->first();

    $data = LocaleScope::run('es', fn () => StatusEventData::fromActivity($row));

    expect($row->properties['diagnostic'])->toBe('SQLSTATE[08006] raw boom')
        ->and(json_encode($data->toArray()))->not->toContain('raw boom')
        ->and(json_encode(StatusStepData::fold([$data])))->not->toContain('raw boom');
});
