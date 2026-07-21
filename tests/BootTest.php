<?php

use Splicewire\Beam\Workflows\Bridge\WorkflowFactory;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

it('boots the service provider and resolves the bridge', function () {
    expect(app()->bound(WorkflowFactory::class))->toBeTrue();
    expect(app(WorkflowFactory::class))->toBeInstanceOf(WorkflowFactory::class);
});

it('publishes the beam-workflows config', function () {
    expect(config('beam-workflows.status_log_name'))->toBe('status');
    expect(config('beam-workflows.node_capability'))->toBe('workflow.apply');
});

it('wraps a symfony/workflow definition into a runnable workflow with a multi-place marking store', function () {
    $definition = new Definition(
        places: ['draft', 'review', 'published'],
        transitions: [
            new Transition('submit_for_review', 'draft', 'review'),
            new Transition('publish', 'review', 'published'),
        ],
        initialPlaces: ['draft'],
    );

    $workflow = app(WorkflowFactory::class)->make($definition, 'composition.lifecycle');

    expect($workflow)->toBeInstanceOf(Workflow::class);

    // Subject carries its marking as a *list* of places (workflow-net ready), not a scalar.
    $subject = new class
    {
        public ?array $marking = null;
    };

    expect($workflow->can($subject, 'submit_for_review'))->toBeTrue();
    expect($workflow->can($subject, 'publish'))->toBeFalse();

    $workflow->apply($subject, 'submit_for_review');

    expect($workflow->getMarking($subject)->getPlaces())->toHaveKey('review');
    expect($workflow->can($subject, 'publish'))->toBeTrue();
});
