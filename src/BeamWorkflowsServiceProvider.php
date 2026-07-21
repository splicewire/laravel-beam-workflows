<?php

namespace Splicewire\Beam\Workflows;

use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Rushing\Popcorn\InvocableRegistry;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Bridge\DefinitionBuilder;
use Splicewire\Beam\Workflows\Bridge\WorkflowFactory;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowApplyInvocable;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRunner;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/**
 * The workflows-arm provider. "A beam can model status."
 *
 * This package sits BESIDE the paid kernels (laravel-circuit-engine /
 * laravel-composition-engine) and never edits their internals — its job is to set the platform
 * precedent for two seams (ADR-0092 free-tier veneer, ADR-0034 no-shared-executor):
 *
 *   - Display (Seam A): a normalized StatusEvent projection over spatie/laravel-activitylog.
 *   - Control (Seam B): a state-machine Circuit node type over symfony/workflow, registered into
 *     the kernel's CapabilityManifest *only if* the circuit-engine is installed (soft dep).
 *
 * register(): merge config; bind the symfony/workflow bridge (the WorkflowFactory that wraps a
 * Definition into a Workflow with an in-memory marking store — the spike's "computation, not
 * persistence" contract). Each state machine passes its own event dispatcher at build time so
 * guard/transition listeners never leak across definitions.
 *
 * boot(): publish config. The Control-seam node registration is additive and guarded on the
 * circuit-engine being present, so a host that only wants the Display substrate boots cleanly
 * with no Circuit dependency.
 */
class BeamWorkflowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam-workflows.php', 'beam-workflows');

        $this->app->singleton(WorkflowFactory::class, fn () => new WorkflowFactory);

        $this->app->singleton(DefinitionBuilder::class, fn () => new DefinitionBuilder);

        $this->app->singleton(StatusEmitter::class, fn ($app) => new StatusEmitter(
            $app['config'],
            $app['events'],
        ));

        // Type seam (PRD v2 §1). The socket's selector: the SchemaTypeProjector is the second
        // (schema) resolution door — a pure mapping stub with no consumer yet — and the
        // TypeIdentityResolver maps any object to its workflow-type key (or null ⇒ unmanaged).
        $this->app->singleton(SchemaTypeProjector::class, fn () => new SchemaTypeProjector);
        $this->app->singleton(TypeIdentityResolver::class, fn ($app) => new TypeIdentityResolver(
            $app->make(SchemaTypeProjector::class),
        ));

        // Binding seam (PRD v2 §2): typeKey → Binding. A binding row existing IS the enable; its
        // absence IS the disable (the generic unmanaged fallback). Pick-one arity, replacement
        // logged through the app logger.
        $this->app->singleton(WorkflowBindingRegistry::class, fn ($app) => new WorkflowBindingRegistry(
            $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
        ));

        // Control seam. The two registries are the host's declaration surfaces (workflows +
        // guards); the runner is the shared Control engine both the node and the Seam C lifecycle
        // drive.
        $this->app->singleton(GuardRegistry::class);
        $this->app->singleton(WorkflowRegistry::class);

        $this->app->singleton(WorkflowRunner::class, fn ($app) => new WorkflowRunner(
            $app->make(DefinitionBuilder::class),
            $app->make(WorkflowFactory::class),
            $app->make(StatusEmitter::class),
            $app->make(GuardRegistry::class),
        ));

        $this->app->singleton(WorkflowApplyInvocable::class, fn ($app) => new WorkflowApplyInvocable(
            (string) config('beam-workflows.node_capability', 'workflow.apply'),
            $app->make(WorkflowRunner::class),
            $app->make(WorkflowRegistry::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam-workflows.php' => $this->app->configPath('beam-workflows.php'),
            ], 'beam-workflows-config');
        }

        $this->registerStateMachineNode();
    }

    /**
     * Register the state-machine node into the Circuit kernel's capability registry — the ADR-0034
     * dispatch seam, NOT a new registry. Guarded on the circuit-engine being installed (soft dep):
     * a host that only wants the Display substrate boots with no Circuit dependency and this is a
     * no-op.
     */
    protected function registerStateMachineNode(): void
    {
        if (! class_exists(InvocableRegistry::class) || ! $this->app->bound(InvocableRegistry::class)) {
            return;
        }

        $this->app->make(InvocableRegistry::class)
            ->register($this->app->make(WorkflowApplyInvocable::class));
    }
}
