<?php

namespace Splicewire\Beam\Workflows;

use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Rushing\Popcorn\InvocableRegistry;
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;
use Splicewire\Beam\Manifest\ManifestSeam;
use Splicewire\Beam\Workflows\Admin\WorkflowAdmin;
use Splicewire\Beam\Workflows\Awaiting\AwaitEffect;
use Splicewire\Beam\Workflows\Awaiting\ClearAwaitingsOnTransition;
use Splicewire\Beam\Workflows\Awaiting\Contracts\AwaitingStore;
use Splicewire\Beam\Workflows\Awaiting\Contracts\WorkflowNotifier;
use Splicewire\Beam\Workflows\Awaiting\EloquentAwaitingStore;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\BlueprintValidator;
use Splicewire\Beam\Workflows\Bridge\DefinitionBuilder;
use Splicewire\Beam\Workflows\Bridge\WorkflowFactory;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\TransitionEffectRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Control\WorkflowApplyInvocable;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRunner;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Migration\MarkingMigrator;
use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;

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
 * boot(): publish config + the tenant migrations (PUBLISH-ONLY, via Laravel-native
 * publishesMigrations — this is a plain provider with no package-tools machinery). The Control-seam
 * node registration is additive and guarded on the circuit-engine being present, so a host that only
 * wants the Display substrate boots cleanly with no Circuit dependency.
 */
class BeamWorkflowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam/workflows.php', 'beam.workflows');

        $this->app->singleton(WorkflowFactory::class, fn () => new WorkflowFactory);

        $this->app->singleton(DefinitionBuilder::class, fn () => new DefinitionBuilder);

        // Default awaiting store (ticket 12). The Eloquent projection over the tenant
        // `workflow_awaitings` table is fully generic (identity-blind, opaque principals), so the
        // package ships it as the sensible default via `bindIf` — a host that needs a different store
        // (a non-Eloquent projection, a differently-tabled one) still overrides. The `WorkflowNotifier`
        // stays deliberately unbound: it is the single identity-touching seam and MUST be host-supplied.
        $this->app->bindIf(AwaitingStore::class, EloquentAwaitingStore::class, shared: true);

        $this->app->singleton(StatusEmitter::class, fn ($app) => new StatusEmitter(
            $app['config'],
            $app['events'],
        ));

        // Type seam (PRD v2 §1). The socket's selector: the SchemaTypeProjector is the second
        // (schema) resolution door — a pure mapping stub with no consumer yet — and the
        // TypeIdentityResolver maps any object to its workflow-type key (or null ⇒ unmanaged).
        $this->app->singleton(SchemaTypeProjector::class, fn () => new SchemaTypeProjector);
        $this->app->singleton(WorkflowTypeRegistry::class);
        $this->app->singleton(TypeIdentityResolver::class, fn ($app) => new TypeIdentityResolver(
            $app->make(SchemaTypeProjector::class),
        ));

        // Binding seam (PRD v2 §2): typeKey → Binding. A binding row existing IS the enable; its
        // absence IS the disable (the generic unmanaged fallback). Pick-one arity, replacement
        // logged through the app logger.
        $this->app->singleton(WorkflowBindingRegistry::class, fn ($app) => new WorkflowBindingRegistry(
            $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
        ));

        // Definition store (PRD v2 §3): the versioned, immutable definition store. Runs on the
        // default connection — the tenant schema under the host's tenancy. NOT shared as a scalar
        // connection: it resolves through the ConnectionResolver so a per-tenant swap is honoured.
        $this->app->singleton(DefinitionStore::class, fn ($app) => new DefinitionStore($app['db']));

        // Marking migrator (ticket 06): the only sanctioned old→new version remap. Model-blind —
        // the host supplies the cohort; this validates, aborts-on-unmappable, or re-pins in one
        // transaction with a Display event per object.
        $this->app->singleton(MarkingMigrator::class, fn ($app) => new MarkingMigrator(
            $app->make(DefinitionStore::class),
            $app->make(StatusEmitter::class),
            $app['db'],
        ));

        // Control seam. The two registries are the host's declaration surfaces (workflows +
        // guards); the runner is the shared Control engine both the node and the Seam C lifecycle
        // drive.
        $this->app->singleton(GuardRegistry::class);
        $this->app->singleton(WorkflowRegistry::class);

        // Effect catalog (v2 notifications): the notifier side of the guard pattern — a transition
        // references effects by name that run after it applies (a notification, a webhook).
        $this->app->singleton(TransitionEffectRegistry::class);

        // Blueprint validator (ticket 05): referential integrity + guard- AND effect-catalog
        // membership. The save path (editor, ticket 08) runs this before a version is written.
        $this->app->singleton(BlueprintValidator::class, fn ($app) => new BlueprintValidator(
            $app->make(GuardRegistry::class),
            $app->make(TransitionEffectRegistry::class),
        ));

        // The model-agnostic workflow-admin behaviour (the seam pass): catalog / lineage reads /
        // validate-then-fork. Hosts wire thin controllers over this; transport + auth + persistence
        // + coverage stay host-side.
        $this->app->singleton(WorkflowAdmin::class, fn ($app) => new WorkflowAdmin(
            $app->make(DefinitionStore::class),
            $app->make(GuardRegistry::class),
            $app->make(WorkflowBindingRegistry::class),
            $app->make(BlueprintValidator::class),
            $app->make(WorkflowTypeRegistry::class),
            $app->make(TransitionEffectRegistry::class),
        ));

        $this->app->singleton(WorkflowRunner::class, fn ($app) => new WorkflowRunner(
            $app->make(DefinitionBuilder::class),
            $app->make(WorkflowFactory::class),
            $app->make(StatusEmitter::class),
            $app->make(GuardRegistry::class),
        ));

        // The generic, model-blind lifecycle control (PRD v2 §4): the replacement for typed
        // per-model lifecycle methods. Wires type → binding → pinned version → guarded transition.
        $this->app->singleton(LifecycleService::class, fn ($app) => new LifecycleService(
            $app->make(TypeIdentityResolver::class),
            $app->make(WorkflowBindingRegistry::class),
            $app->make(DefinitionStore::class),
            $app->make(WorkflowRegistry::class),
            $app->make(WorkflowRunner::class),
            $app->make(TransitionEffectRegistry::class),
            $app->make('events'),
        ));

        // Generic actuation seam (beam-workflows v2): the SubjectResolverRegistry maps a `kind` slug
        // to a record finder (a host registers one line per managed type); the WorkflowActuator drives
        // ANY resolved record's lifecycle over the generic LifecycleService — no per-model endpoint.
        $this->app->singleton(SubjectResolverRegistry::class);
        $this->app->singleton(WorkflowActuator::class, fn ($app) => new WorkflowActuator(
            $app->make(SubjectResolverRegistry::class),
            $app->make(LifecycleService::class),
        ));

        $this->app->singleton(WorkflowApplyInvocable::class, fn ($app) => new WorkflowApplyInvocable(
            (string) config('beam.workflows.node_capability', 'workflow.apply'),
            $app->make(WorkflowRunner::class),
            $app->make(WorkflowRegistry::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam/workflows.php' => $this->app->configPath('beam/workflows.php'),
            ], 'beam-workflows-config');

            $this->bootMigrations();
        }

        $this->registerStateMachineNode();
        $this->registerAwaitingSeam();
        $this->describeWorkflowManifests();
    }

    /**
     * Describe this package's workflow registries into the index of indexes (beam-manifest-index). Owner
     * self-registration, down into beam-core's {@see ManifestIndex} — the same direction as the install /
     * doctor manifests, and topology-safe (beam-workflows depends DOWN on laravel-beam). Note the axes
     * diverge within one seam: every registry here is a singleton-accumulator, but WorkflowTypeRegistry
     * reads RUN-ALL (the dropdown enumeration) while the rest resolve PICK-ONE by key.
     */
    private function describeWorkflowManifests(): void
    {
        $index = $this->app->make(ManifestIndex::class);
        $pkg = 'splicewire/laravel-beam-workflows';

        foreach ([
            ['WorkflowRegistry', 'named workflow blueprints (state machines), resolved by name', ManifestArity::PickOne,
                'resolve the singleton and register(name, WorkflowBlueprint|array) from your provider', WorkflowRegistry::class, 30],
            ['WorkflowTypeRegistry', 'governable workflow types (key + label) for the admin dropdown', ManifestArity::RunAll,
                'resolve the singleton and register(key, ?label) from your provider', WorkflowTypeRegistry::class, 31],
            ['WorkflowBindingRegistry', 'typeKey → Binding mappings (presence IS the enable), resolved by type', ManifestArity::PickOne,
                'resolve the singleton and bind(typeKey, lineageRef, params) from your provider', WorkflowBindingRegistry::class, 32],
            ['GuardRegistry', 'transition guard callables by reference, with editor-menu catalog entries', ManifestArity::PickOne,
                'resolve the singleton and register(ref, callable, ?label, paramsSchema) from your provider', GuardRegistry::class, 33],
            ['TransitionEffectRegistry', 'post-transition effect callables by reference, with catalog entries', ManifestArity::PickOne,
                'resolve the singleton and register(ref, callable, ?label, paramsSchema) from your provider', TransitionEffectRegistry::class, 34],
            ['SubjectResolverRegistry', 'subject finders by kind slug (id → model) for generic actuation', ManifestArity::PickOne,
                'resolve the singleton and register(kind, fn($id) => ?Model) from your provider', SubjectResolverRegistry::class, 35],
        ] as [$name, $of, $arity, $hint, $where, $order]) {
            $index->describe(new ManifestDescriptor(
                name: $name,
                of: $of,
                seam: ManifestSeam::SingletonAccumulator,
                arity: $arity,
                registerHint: $hint,
                where: $where,
                package: $pkg, order: $order,
            ));
        }
    }

    /**
     * PUBLISH-ONLY tenant migrations — the idiomatic pattern for a PLAIN ServiceProvider, mirroring
     * the beam-core PackageServiceProvider exemplar (undo of the recohere runtime `--path` push).
     *
     * A plain provider has no spatie/laravel-package-tools machinery, so this uses Laravel's native
     * {@see ServiceProvider::publishesMigrations()} (Laravel 11+). It does NOT loadMigrationsFrom and
     * does NOT push onto `tenancy.migration_parameters.--path`: the package never runs these at
     * runtime. `vendor:publish --tag=beam-workflows-migrations` drops the copies into the HOST's
     * `database/migrations/tenant/`, and the host's Stancl tenant pass runs them.
     *
     * TENANT-ONLY. The definition-store, bindings, and awaitings tables are per-tenant workflow data,
     * so they publish ONLY into `database/migrations/tenant/` — there is NO flat/central twin, and no
     * `Schema::hasTable()` dup-guard (that guard exists only for a ubiquitous table's tenant twin in a
     * host that migrates both passes into one schema; these tables have no central pass to collide
     * with).
     *
     * The publishable source files carry their own valid timestamp prefix and ship as plain `.php`.
     * With `database.migrations.update_date_on_publish` at its default (false), native
     * `publishesMigrations` copies each file verbatim — one correctly-timestamped migration per table,
     * no double-stamp. These are leaf tables (no external migration references them), so the frozen
     * timestamps order correctly against the rest of the tenant stack.
     */
    protected function bootMigrations(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/tenant' => $this->app->databasePath('migrations/tenant'),
        ], 'beam-workflows-migrations');
    }

    /**
     * Wire the awaiting seam (beam-workflows-ux tickets 07/09/11): the one generic `workflow.await`
     * effect (into the effect catalog) + the synchronous clear-on-leave listener. Both call the
     * host-bound {@see AwaitingStore} /
     * {@see WorkflowNotifier} contracts and are INERT
     * until the host binds them — so a host that never binds a store boots cleanly with the effect
     * merely visible-but-dormant in the catalog.
     *
     * The listener is registered as a plain (non-queued) listener so it runs during the event dispatch
     * inside `react()`, BEFORE the effect stamp — the clear-then-stamp ordering ticket 07 mandates.
     */
    protected function registerAwaitingSeam(): void
    {
        $this->app->make(TransitionEffectRegistry::class)->register(
            AwaitEffect::REF,
            $this->app->make(AwaitEffect::class),
            label: 'Await — notify recipients + add a "waiting on you" inbox item',
            paramsSchema: AwaitEffect::paramsSchema(),
        );

        $this->app['events']->listen(
            WorkflowTransitioned::class,
            [ClearAwaitingsOnTransition::class, 'handle'],
        );
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
