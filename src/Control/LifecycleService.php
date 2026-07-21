<?php

namespace Splicewire\Beam\Workflows\Control;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Binding\Binding;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
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
    ) {}

    /**
     * Attempt one transition on a managed model. On success the resulting marking projects onto the
     * model's status attribute, the version pin is recorded if newly resolved, the model is saved,
     * and a Display event is emitted. A rejection (illegal/guarded/unmanaged) writes nothing and
     * returns the reason in `blockers`.
     *
     * @param  array<string, mixed>  $params  Call-time guard params, merged over the binding's params.
     */
    public function transition(Model $model, string $transitionName, array $params = [], ?string $runId = null): TransitionResult
    {
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

        $subject = MarkingSubject::fromPlaces(
            [$this->currentPlace($model, $blueprint)],
            $this->guardContext($binding, $params),
        );

        $result = $this->runner->apply($blueprint, $subject, $transitionName, statusSubject: $model, runId: $runId);

        if ($result->applied) {
            $this->project($model, $result->marking, $versionId);
        }

        return $result;
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
            [$this->currentPlace($model, $blueprint)],
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
     * Project a successful marking back onto the model: the (single) place onto the status
     * attribute, the resolved version onto the pin (if any), then persist.
     *
     * @param  list<string>  $marking
     */
    protected function project(Model $model, array $marking, ?string $versionId): void
    {
        $statusAttr = $model instanceof WorkflowManaged ? $model->workflowStatusAttribute() : 'status';
        $model->{$statusAttr} = $marking[0] ?? $this->currentPlace($model);

        if ($versionId !== null && $model instanceof WorkflowManaged) {
            $model->{$model->workflowVersionAttribute()} = $versionId;
        }

        $model->save();
    }

    /**
     * The model's current place as a plain string — the status attribute normalised (a backed enum
     * yields its value), defaulting to the blueprint's initial place when the attribute is empty.
     */
    protected function currentPlace(Model $model, ?WorkflowBlueprint $blueprint = null): string
    {
        $statusAttr = $model instanceof WorkflowManaged ? $model->workflowStatusAttribute() : 'status';
        $value = $model->{$statusAttr} ?? null;

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        return $blueprint->initialMarking[0] ?? 'draft';
    }
}
