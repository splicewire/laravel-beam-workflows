<?php

use Illuminate\Support\Facades\Event;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\MarkingSubject;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRunner;
use Splicewire\Beam\Workflows\Display\Events\StatusEmitted;
use Splicewire\Beam\Workflows\Display\State;

it('keeps a guarded draft publish separate from an unguarded review publish with the same name', function () {
    $registry = app(WorkflowRegistry::class);
    $registry->register('runner.safety', [
        'name' => 'runner.safety',
        'places' => ['draft', 'review', 'published'],
        'initial' => ['draft'],
        'transitions' => [
            ['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'review_required'],
            ['name' => 'publish', 'from' => 'review', 'to' => 'published'],
        ],
    ]);
    app(GuardRegistry::class)->register('review_required', fn () => 'Review is required.');
    $blueprint = $registry->get('runner.safety');
    $runner = app(WorkflowRunner::class);
    $draft = MarkingSubject::fromPlaces(['draft']);
    $review = MarkingSubject::fromPlaces(['review']);

    expect($runner->enabled($blueprint, $draft))->toBe([])
        ->and($runner->enabled($blueprint, $review))->toBe(['publish']);

    $blocked = $runner->apply($blueprint, $draft, 'publish');
    $applied = $runner->apply($blueprint, $review, 'publish');

    expect($blocked->applied)->toBeFalse()
        ->and($blocked->blockers)->toBe(['Review is required.'])
        ->and($draft->places())->toBe(['draft'])
        ->and($applied->applied)->toBeTrue()
        ->and($review->places())->toBe(['published']);
});

it('does not fail an applied transition when computing its display status evaluates a broken next guard', function () {
    $registry = app(WorkflowRegistry::class);
    $registry->register('runner.safety', [
        'name' => 'runner.safety',
        'places' => ['draft', 'review', 'published'],
        'initial' => ['draft'],
        'transitions' => [
            ['name' => 'submit', 'from' => 'draft', 'to' => 'review'],
            ['name' => 'publish', 'from' => 'review', 'to' => 'published', 'guard' => 'unavailable_review'],
        ],
    ]);
    app(GuardRegistry::class)->register('unavailable_review', fn () => throw new RuntimeException('Review service unavailable.'));
    $subject = MarkingSubject::fromPlaces(['draft']);

    $result = app(WorkflowRunner::class)->apply($registry->get('runner.safety'), $subject, 'submit');

    expect($result->applied)->toBeTrue()
        ->and($result->marking)->toBe(['review'])
        ->and($subject->places())->toBe(['review']);
});

it('lets a persistence owner defer status while retaining default status for in-memory execution', function () {
    $registry = app(WorkflowRegistry::class);
    $registry->register('runner.safety', [
        'name' => 'runner.safety',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]);
    $blueprint = $registry->get('runner.safety');
    $runner = app(WorkflowRunner::class);
    $subject = MarkingSubject::fromPlaces(['draft']);
    $context = new TransitionContext(actor: 'user:editor', runId: 'run-deferred');
    Event::fake([StatusEmitted::class]);

    $result = $runner->apply($blueprint, $subject, 'publish', context: $context, emitStatus: false);

    expect($result->applied)->toBeTrue();
    Event::assertNotDispatched(StatusEmitted::class);

    $runner->emitStatus($blueprint, $subject, 'publish', context: $context);

    Event::assertDispatched(
        StatusEmitted::class,
        fn ($event) => $event->runId === 'run-deferred'
            && $event->actor === 'user:editor'
            && $event->status->state === State::Complete,
    );

    $runner->apply($blueprint, MarkingSubject::fromPlaces(['draft']), 'publish');

    Event::assertDispatchedTimes(StatusEmitted::class, 2);
});

it('keeps an applied marking when the status transport fails', function () {
    $registry = app(WorkflowRegistry::class);
    $registry->register('runner.safety', [
        'name' => 'runner.safety',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]);
    Event::listen(StatusEmitted::class, fn () => throw new RuntimeException('Status transport unavailable.'));
    $subject = MarkingSubject::fromPlaces(['draft']);

    $result = app(WorkflowRunner::class)->apply($registry->get('runner.safety'), $subject, 'publish');

    expect($result->applied)->toBeTrue()
        ->and($subject->places())->toBe(['published']);
});

it('propagates an authoritative guard failure without changing the marking', function () {
    $registry = app(WorkflowRegistry::class);
    $registry->register('runner.safety', [
        'name' => 'runner.safety',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'unavailable_review']],
    ]);
    app(GuardRegistry::class)->register('unavailable_review', fn () => throw new RuntimeException('Review service unavailable.'));
    $subject = MarkingSubject::fromPlaces(['draft']);

    expect(fn () => app(WorkflowRunner::class)->apply($registry->get('runner.safety'), $subject, 'publish'))
        ->toThrow(RuntimeException::class, 'Review service unavailable.');
    expect($subject->places())->toBe(['draft']);
});
