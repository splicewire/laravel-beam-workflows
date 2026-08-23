<?php

namespace Splicewire\Beam\Workflows;

use Psr\Log\LoggerInterface;
use Rushing\Popcorn\Registries\RegistryIndex;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
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
use Splicewire\Beam\Workflows\Control\WorkflowInvocableRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowRunner;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusManager;
use Splicewire\Beam\Workflows\Doctor\BeamWorkflowsMigrationsAudit;
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
 * packageRegistered(): merge config; bind the symfony/workflow bridge (the WorkflowFactory that wraps a
 * Definition into a Workflow with an in-memory marking store — the spike's "computation, not
 * persistence" contract). Each state machine passes its own event dispatcher at build time so
 * guard/transition listeners never leak across definitions.
 *
 * packageBooted(): publish config + register the doctor audit + register manifests. The
 * tenant migrations ship PUBLISH-ONLY via spatie/laravel-package-tools' `->hasMigrations([...])`
 * (see {@see self::configurePackage()}) — the estate-wide publish-only stub convention, mirroring
 * beam-core's own `BeamServiceProvider`. The Control-seam node registration is additive and guarded
 * on the circuit-engine being present, so a host that only wants the Display substrate boots cleanly
 * with no Circuit dependency.
 *
 * The tenant migrations (workflow_definition_lineages/workflow_definition_versions,
 * workflow_bindings, workflow_awaitings) ship as PUBLISH-ONLY spatie/laravel-package-tools stubs —
 * the idiomatic pattern for a PackageServiceProvider. `runsMigrations` stays FALSE, so beam-workflows
 * never loads them at runtime; `vendor:publish --tag=beam-workflows-migrations` re-stamps + sequences
 * timestamped copies into the HOST's `database/migrations/tenant/`, and the host's Stancl tenant pass
 * runs them. TENANT-ONLY — the definition-store, bindings, and awaitings tables are per-tenant
 * workflow data, so there is NO flat/central twin (unlike beam-core's ubiquitous tables), and no
 * `Schema::hasTable()` dup-guard.
 */
class BeamWorkflowsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-workflows')
            ->hasConfigFile(['beam/workflows'])
            ->hasMigrations([
                'tenant/create_workflow_definition_tables',
                'tenant/create_workflow_bindings_table',
                'tenant/create_workflow_awaitings_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(WorkflowFactory::class, fn () => new WorkflowFactory);

        $this->app->singleton(DefinitionBuilder::class, fn () => new DefinitionBuilder);

        // Default awaiting store (ticket 12). The Eloquent projection over the tenant
        // `workflow_awaitings` table is fully generic (identity-blind, opaque principals), so the
        // package ships it as the sensible default via `bindIf` — a host that needs a different store
        // (a non-Eloquent projection, a differently-tabled one) still overrides. The `WorkflowNotifier`
        // stays deliberately unbound: it is the single identity-touching seam and MUST be host-supplied.
        $this->app->bindIf(AwaitingStore::class, EloquentAwaitingStore::class, shared: true);

        // The dispatcher goes in as a RESOLVER, not an instance: this is a singleton, and
        // `Event::fake()` rebinds `events`, so a captured dispatcher makes a fake silently
        // ineffective for any emitter built before it (see StatusEmitter).
        $this->app->singleton(StatusEmitter::class, fn ($app) => new StatusEmitter(
            $app['config'],
            fn () => $app['events'],
        ));

        // The Display front door (beam-facade ticket 32): `Splicewire\Beam\Workflows\Facades\Status`
        // resolves here. The emitter is constructor-injected rather than pulled per call — it is a
        // singleton, so there is no per-call rebinding to preserve.
        $this->app->singleton(StatusManager::class, fn ($app) => new StatusManager(
            $app->make(StatusEmitter::class),
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

        $this->app->singleton(WorkflowInvocableRegistry::class);

        $this->app->singleton(WorkflowApplyInvocable::class, fn ($app) => new WorkflowApplyInvocable(
            (string) config('beam.workflows.node_capability', 'workflow.apply'),
            $app->make(WorkflowRunner::class),
            $app->make(WorkflowRegistry::class),
        ));
    }

    public function packageBooted(): void
    {
        $this->registerStateMachineNode();
        $this->registerAwaitingSeam();
        $this->registerDoctorAudit();

        // Self-register into beam-core's install manifest so `splicewire:beam:install` publishes
        // this package's tenant migrations with the rest of the stack. Recohere gap: already
        // publish-only converted, but never wired into the manifest.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-workflows',
                publishTags: ['beam-workflows-config', 'beam-workflows-migrations'],
                migrates: true,
            );
        }
    }

    /**
     * beam-workflows is itself an "operator" of the estate-wide publish-only stub migrations
     * convention (this provider's class docblock above) — self-registers the doctor/operator check on
     * ITS OWN migrations, DOWN into beam-core's {@see BeamDoctorManifest}, guarded on the manifest
     * being bound (the notifications-twin precedent) so the package still boots in a host running an
     * older beam-core that predates it.
     */
    private function registerDoctorAudit(): void
    {
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                package: 'splicewire/laravel-beam-workflows',
                audit: BeamWorkflowsMigrationsAudit::class,
                gate: false,
            );
        }
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
     * Register the state-machine node into workflows' OWN capability registry — the ADR-0034 dispatch
     * seam, now rooted where this package owns it.
     *
     * Unguarded, deliberately. The old `class_exists(...) || ! bound(...)` guard was a soft-dependency
     * test on the circuit engine, because the capability was being written into a pool circuits bound;
     * a host wanting only the Display substrate would silently register nothing. That is ticket 04 D1's
     * defect class — a package's registration present or absent by host composition. Owning the root
     * removes the condition entirely: the capability always exists, and whether a circuit node
     * dispatches to it is the host's business.
     */
    protected function registerStateMachineNode(): void
    {
        $registry = $this->app->make(WorkflowInvocableRegistry::class)
            ->register($this->app->make(WorkflowApplyInvocable::class));

        // An owner registers DOWN into the index from its own boot; the index never reaches up.
        $this->app->make(RegistryIndex::class)->describe($registry);
    }
}
