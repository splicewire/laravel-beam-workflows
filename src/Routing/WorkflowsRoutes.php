<?php

namespace Splicewire\Beam\Workflows\Routing;

use Closure;
use Illuminate\Routing\Router;
use Splicewire\Beam\Routing\ApiRoot;
use Splicewire\Beam\Workflows\History\WorkflowHistoryResources;
use Splicewire\Beam\Workflows\Reactions\WorkflowReactionResources;

/** Optional capabilities stay independent. The host supplies its authenticated tenant boundary. */
final class WorkflowsRoutes
{
    public static function reactions(): void
    {
        self::atRoot(fn () => WorkflowReactionResources::mount('reactions'));
    }

    public static function history(): void
    {
        self::atRoot(fn () => WorkflowHistoryResources::mount('history'));
    }

    private static function atRoot(Closure $routes): void
    {
        ApiRoot::mount(app(Router::class), 'beam.workflows.api_root', 'workflows', fn (Router $router) => $router->name('beam.workflows.')->group($routes));
    }
}
