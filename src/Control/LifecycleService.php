<?php

namespace Splicewire\Beam\Workflows\Control;

use BackedEnum;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Splicewire\Beam\Workflows\Binding\Binding;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Events\WorkflowTransitioned;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;
use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/**
 * The GENERIC control entry point (PRD v2 §4) — the model-blind replacement for v1's typed
 * `CompositionLifecycle::publish()` methods. `transition($model, $name, $params)` is all any managed
 * object needs: it wires the four socket layers together at decision time —
 *
 *   1. resolve the model's type key                    (ticket 01, {@see TypeIdentityResolver})
 *   2. resolve the binding + its params                (ticket 02, {@see WorkflowBindingRegistry})
 *   3. resolve the model's PINNED definition version    (ticket 03, {@see DefinitionStore})
 *   4. hydrate a {@see MarkingSubject} from the model's current status, run guards, and on success
 *      project the marking back onto the status attribute + pin the version + emit the Display event
 *      ({@see WorkflowRunner}).
 *
 * Nothing here knows the word "Composition": the same call governs a batch run, a knowledge entry,
 * or any {@see WorkflowManaged} model. An unmanaged object (no type key, or a type with no binding)
 * returns an un-applied {@see TransitionResult} carrying the reason — the generic fallback, never an
 * exception.
 *
 * SINGLE-PLACE PERSISTENCE IS THIS SEAM'S ONE HARD LIMIT. The engine computes multi-token
 * (workflow-net) markings for real — {@see \Splicewire\Beam\Workflows\Bridge\WorkflowFactory} builds
 * every workflow with `singleState: false` and {@see MarkingSubject} carries a list — but a LIFECYCLE
 * projects that marking onto a scalar status attribute, which has room for exactly one place. A
 * transition that would produce more is REFUSED here, with the reason in `blockers` and nothing
 * written; see {@see self::unpersistable()}. It formerly wrote the first place and returned
 * `applied: true`, discarding the rest in silence. Multi-token runs belong on the Control-seam node
 * ({@see WorkflowApplyInvocable}), where the marking rides the port envelope and the host persists it.
 *
 * Version resolution is layered so it works both with the stored, versioned lineage (ticket 03) AND
 * the code-registered blueprint (v1 back-compat), in this order:
 *   - the model already carries a pin → that exact immutable version's blueprint;
 *   - the binding's lineage exists in the store → its ACTIVE version (and the model is pinned to it);
 *   - else the lineageRef is a registered blueprint name → that blueprint (no pin — v1's path).
 */
class LifecycleService
{
    public function __construct(
        protected TypeIdentityResolver $types,
        protected WorkflowBindingRegistry $bindings,
        protected DefinitionStore $store,
        protected WorkflowRegistry $registry,
        protected WorkflowRunner $runner,
        protected TransitionEffectRegistry $effects,
        protected Dispatcher $events,
    ) {}

    /**
     * Attempt one transition on a managed model. On success the resulting marking projects onto the
     * model's status attribute, the version pin is recorded if newly resolved, the model is saved,
     * and a Display event is emitted. A rejection (illegal/guarded/unmanaged) writes nothing and
     * returns the reason in `blockers`.
     *
     * @param  array<string, mixed>  $params  Call-time guard params, merged over the binding's params.
     * @param  TransitionContext|null  $context  The run id + opaque actor token for this attempt (the
     *                                           host supplies the actor; the engine forwards it, never
     *                                           resolving it). Null = an ungrouped, actorless move.
     */
    public function transition(Model $model, string $transitionName, array $params = [], ?TransitionContext $context = null): TransitionResult
    {
        $context ??= new TransitionContext;

        $resolved = $this->resolve($model);

        if ($resolved === null) {
            return new TransitionResult(
                marking: [$this->currentPlace($model)],
                transition: $transitionName,
                applied: false,
                blockers: ['This object is not managed by a workflow (no binding for its type).'],
            );
        }

        [$blueprint, $binding, $versionId] = $resolved;

        $from = $this->currentPlaces($model, $blueprint);

        // The marking we would START from is already unpersistable (a blueprint declaring a
        // multi-place `initial` against a model that has never been written). Refuse before running
        // anything, rather than silently starting from its first place.
        if (($blocker = $this->unpersistable($from, "the current marking of [{$transitionName}]'s subject")) !== null) {
            return new TransitionResult(marking: $from, transition: $transitionName, applied: false, blockers: [$blocker]);
        }

        $subject = MarkingSubject::fromPlaces($from, $this->guardContext($binding, $params));

        $result = $this->runner->apply($blueprint, $subject, $transitionName, statusSubject: $model, context: $context);

        if (! $result->applied) {
            return $result;
        }

        // The move was legal and symfony mutated the THROWAWAY subject — the model is still
        // untouched. If the resulting marking holds more than one place there is nowhere to put it
        // (see {@see self::project()}), so the honest answer is a refusal, not a truncated write
        // reported as success. Nothing is persisted and no effects fire.
        if (($blocker = $this->unpersistable($result->marking, "the marking [{$transitionName}] produces")) !== null) {
            return new TransitionResult(
                marking: $from,
                transition: $transitionName,
                applied: false,
                blockers: [$blocker],
            );
        }

        $this->project($model, $result->marking, $versionId);
        $this->react($model, $blueprint, $transitionName, $from, $result->marking, $versionId, $context);

        return $result;
    }

    /**
     * The engine's ONE persistence constraint, stated once: a lifecycle projects its marking onto a
     * scalar status attribute, so a marking holding more than one place has nowhere to go.
     *
     * This is a fact about the PACKAGE, not about a host — {@see \Splicewire\Beam\Workflows\Bridge\WorkflowFactory} builds every
     * workflow with `singleState: false` and {@see MarkingSubject} carries a genuine list, because
     * multi-place computation is real and correct on the Control-seam node path
     * ({@see WorkflowApplyInvocable}), where the marking rides the port envelope and the host owns
     * persistence. It is only THIS path — project-onto-a-column — that cannot represent it. So the
     * constraint lives here, at the persistence seam, and deliberately not in
     * {@see \Splicewire\Beam\Workflows\Blueprint\BlueprintValidator}, which would wrongly forbid a
     * workflow-net that the node seam runs perfectly well.
     *
     * It surfaces as a BLOCKER rather than an exception because that is this seam's whole contract:
     * a caller branches on `applied` and never catches (see {@see TransitionResult}). What it must
     * never do again is what it did before — write `$marking[0]` and return `applied: true`, which
     * discarded every place after the first with no exception and no log.
     *
     * @param  list<string>  $places
     * @return string|null a human-readable blocker, or null when the marking is persistable
     */
    protected function unpersistable(array $places, string $subject): ?string
    {
        if (count($places) <= 1) {
            return null;
        }

        return "This workflow is multi-token: {$subject} holds ".count($places).' places ['
            .implode(', ', $places).'], and a lifecycle persists a marking onto a single scalar status '
            .'attribute. Nothing was written. Either narrow the blueprint so the transition produces one '
            .'place, or drive this workflow through the Control-seam node, where the marking rides the '
            .'port envelope and the host owns persistence.';
    }

    /**
     * React to an applied transition: fire the structured {@see WorkflowTransitioned} event (for code
     * listeners) and run the transition's named EFFECTS (the notifier catalog). Reactions are
     * fire-and-forget — an effect that throws is isolated so it can never roll back the Control change
     * that already committed.
     *
     * ONE APPLY FIRES EACH EFFECT ONCE. A transition name may legally be declared several times in a
     * blueprint — that is the workflow-net idiom for OR-ing source places, since a multi-place `from`
     * means "consume ALL of these" rather than "any of these". Iterating declarations naively fired
     * every matching declaration's effects, so a `fail` declared from four places ran its effects four
     * times on a single apply: four teardown attempts, four duplicate audit notes, four notifications
     * to the same reviewer. Effects are deduplicated by reference here, with the first declaration's
     * params winning — a blueprint that wants an effect to run twice should reference it twice.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    protected function react(Model $model, WorkflowBlueprint $blueprint, string $transitionName, array $from, array $to, ?string $versionId, TransitionContext $context): void
    {
        $event = new WorkflowTransitioned($model, $transitionName, $from, $to, $versionId, $context->runId, $context->actor);

        $this->events->dispatch($event);

        foreach ($this->effectsFor($blueprint, $transitionName) as $ref => $params) {
            try {
                ($this->effects->get($ref))($event, $params);
            } catch (\Throwable $e) {
                report($e); // Display-side, lossy-OK: never break the committed transition.
            }
        }
    }

    /**
     * The registered effects a transition NAME carries, deduplicated by reference and in declaration
     * order. Unregistered references are skipped rather than raising — unlike a guard, which fails
     * closed, a missing effect must not undo a transition that has already committed.
     *
     * @return array<string, array<string, mixed>> effect ref => author-set params
     */
    protected function effectsFor(WorkflowBlueprint $blueprint, string $transitionName): array
    {
        $resolved = [];

        foreach ($blueprint->transitions as $transition) {
            if ($transition->name !== $transitionName) {
                continue;
            }

            foreach ($transition->effects as $ref) {
                if (isset($resolved[$ref]) || ! $this->effects->has($ref)) {
                    continue;
                }

                $resolved[$ref] = $transition->effectParams($ref);
            }
        }

        return $resolved;
    }

    /**
     * The transitions available on the model right now — the backend-computed list the stepper
     * renders as action buttons (ticket 07). Empty for an unmanaged object.
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    public function available(Model $model, array $params = []): array
    {
        $resolved = $this->resolve($model);

        if ($resolved === null) {
            return [];
        }

        [$blueprint, $binding] = $resolved;

        $subject = MarkingSubject::fromPlaces(
            $this->currentPlaces($model, $blueprint),
            $this->guardContext($binding, $params),
        );

        return $this->runner->enabled($blueprint, $subject);
    }

    /**
     * Whether a workflow governs this model at all (typed AND bound).
     */
    public function manages(Model $model): bool
    {
        return $this->resolve($model) !== null;
    }

    /**
     * The full DEFINITION PROJECTION a model-blind runtime UI needs (the `<WorkflowStepper>`, ticket
     * 07): the type key, the pinned version's places + transitions, the current marking, and the
     * backend-computed available transitions. `null` for an unmanaged model. This is the endpoint
     * shape ticket 04 anticipated — everything the stepper renders, computed server-side so the UI
     * never hardcodes a graph or a button list.
     *
     * @param  array<string, mixed>  $params
     * @return array{type: string, places: list<string>, transitions: list<array{name: string, from: list<string>, to: list<string>}>, current: string, available: list<string>}|null
     */
    public function projection(Model $model, array $params = []): ?array
    {
        $resolved = $this->resolve($model);

        if ($resolved === null) {
            return null;
        }

        [$blueprint, $binding] = $resolved;

        return [
            // The type key that actually governs this object (the matched binding), which for a
            // schema-driven record may be its schema key rather than its class key.
            'type' => $binding->typeKey,
            'places' => $blueprint->places,
            'transitions' => array_map(
                fn ($t) => ['name' => $t->name, 'from' => $t->from, 'to' => $t->to],
                $blueprint->transitions,
            ),
            'current' => $this->currentPlace($model, $blueprint),
            'available' => $this->available($model, $params),
        ];
    }

    /**
     * Resolve the model to `[blueprint, binding, versionId|null]`, or `null` if unmanaged.
     *
     * @return array{0: WorkflowBlueprint, 1: Binding, 2: string|null}|null
     */
    protected function resolve(Model $model): ?array
    {
        $binding = $this->bindings->forObject($model, $this->types);

        if ($binding === null) {
            return null; // untyped or unbound ⇒ unmanaged.
        }

        // 1. Already pinned → that exact immutable version.
        $pin = $model instanceof WorkflowManaged
            ? ($model->{$model->workflowVersionAttribute()} ?? null)
            : null;

        if ($pin !== null) {
            $version = $this->store->version((string) $pin);
            if ($version !== null) {
                return [$version->toBlueprint(), $binding, $version->id];
            }
        }

        // 2. The binding's lineage is in the store → its active version (pin the model to it).
        $active = $this->store->activeVersion($binding->lineageRef);
        if ($active !== null) {
            return [$active->toBlueprint(), $binding, $active->id];
        }

        // 3. Back-compat: the lineageRef is a registered blueprint name (v1's code-only path).
        if ($this->registry->has($binding->lineageRef)) {
            return [$this->registry->get($binding->lineageRef), $binding, null];
        }

        return null;
    }

    /**
     * The guard context: the binding's params (e.g. `require_review`) with call-time params merged
     * over them (e.g. a composition's `stale_cells`). This is the single place the two param sources
     * fuse before reaching a guard closure.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function guardContext(Binding $binding, array $params): array
    {
        return array_merge($binding->params, $params);
    }

    /**
     * Project a successful marking back onto the model: the single place onto the status attribute,
     * the resolved version onto the pin (if any), then persist.
     *
     * SINGLE-PLACE BY CONSTRUCTION, and it raises rather than truncating. The status attribute is a
     * scalar column, so this method has exactly one slot; it used to write `$marking[0]` and return,
     * which silently discarded every further place while the transition still reported
     * `applied: true`. Callers filter through {@see self::unpersistable()} before reaching here, so a
     * multi-place marking arriving at this point is a broken caller — an invariant the author of a
     * subclass could have gotten right — and it throws.
     *
     * @param  list<string>  $marking
     *
     * @throws \LogicException when handed a marking this seam cannot represent.
     */
    protected function project(Model $model, array $marking, ?string $versionId): void
    {
        if (count($marking) > 1) {
            throw new LogicException(
                'LifecycleService::project() was handed a '.count($marking).'-place marking ['
                .implode(', ', $marking).']. A lifecycle persists onto a scalar status attribute and '
                .'cannot represent it; filter through unpersistable() before projecting.',
            );
        }

        $statusAttr = $model instanceof WorkflowManaged ? $model->workflowStatusAttribute() : 'status';
        $model->{$statusAttr} = $marking[0] ?? $this->currentPlace($model);

        if ($versionId !== null && $model instanceof WorkflowManaged) {
            $model->{$model->workflowVersionAttribute()} = $versionId;
        }

        $model->save();
    }

    /**
     * The model's current marking as a LIST — the status attribute normalised (a backed enum yields
     * its value), or the blueprint's whole initial marking when the attribute is empty.
     *
     * The list is what the read side actually needs. A stored status is always one place (a scalar
     * column), but a blueprint's `initial` is declared as a list, and reading only its first element
     * was the read-side twin of the write-side truncation: a multi-place `initial` started the
     * subject in one place and nothing said so. Returning the full list lets
     * {@see self::unpersistable()} see it and refuse.
     *
     * @return list<string>
     */
    protected function currentPlaces(Model $model, ?WorkflowBlueprint $blueprint = null): array
    {
        $statusAttr = $model instanceof WorkflowManaged ? $model->workflowStatusAttribute() : 'status';
        $value = $model->{$statusAttr} ?? null;

        if ($value instanceof BackedEnum) {
            return [(string) $value->value];
        }

        if ($value !== null && $value !== '') {
            return [(string) $value];
        }

        return $blueprint?->initialMarking ?: ['draft'];
    }

    /**
     * The model's current place as a plain string — the first place of {@see self::currentPlaces()}.
     * Kept for the scalar read surfaces (`projection()['current']`, the unmanaged fallback), which
     * describe a single-place lifecycle by contract.
     */
    protected function currentPlace(Model $model, ?WorkflowBlueprint $blueprint = null): string
    {
        return $this->currentPlaces($model, $blueprint)[0] ?? 'draft';
    }
}
