<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Workflows\History\WorkflowHistoryResources;
use Splicewire\Beam\Workflows\Reactions\WorkflowReactionResources;
use Splicewire\Beam\Workflows\Routing\WorkflowsRoutes;

it('exports optional reaction and history mounts independently with exact legacy middleware', function () {
    $this->app->register(Splicewire\Beam\BeamServiceProvider::class);
    Route::prefix('legacy')->name('legacy.')->middleware('host')->group(function () {
        WorkflowReactionResources::mount('reactions');
        WorkflowHistoryResources::mount('history');
    });
    Route::middleware('host')->group(fn () => WorkflowsRoutes::reactions());
    $routes = app('router')->getRoutes();
    expect(collect($routes->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'beam/workflows/history')))->toBeFalse();
    Route::middleware('host')->group(fn () => WorkflowsRoutes::history());
    $new = collect($routes->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'beam/workflows/'));
    expect($new->count())->toBe(5);
    foreach ($new as $r) {
        $suffix = substr($r->uri(), strlen('beam/workflows/'));
        $old = collect($routes->getRoutes())->first(fn ($o) => $o->uri() === 'legacy/'.$suffix && $o->methods() === $r->methods());
        expect($old)->not->toBeNull();
        expect($r->getActionName())->toBe($old->getActionName())->and($r->gatherMiddleware())->toBe($old->gatherMiddleware())
            ->and($r->defaults)->toBe($old->defaults)
            ->and($r->wheres)->toBe($old->wheres);
    }
    config(['beam.workflows.api_root' => 'custom/lifecycle']);
    WorkflowsRoutes::history();
    expect(collect($routes->getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'custom/lifecycle/history/')))->toBeTrue();
});
