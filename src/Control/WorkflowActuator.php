<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Control\Contracts\ProvidesGuardContext;

/**
 * The GENERIC runtime actuation surface (beam-workflows v2). Everything a host needs to drive ANY
 * managed record's lifecycle over HTTP — resolve the subject, read its projection, apply a
 * transition — with no per-model code. A host wires a thin generic controller over this + registers
 * a resolver per {@see SubjectResolverRegistry}; adding a new managed type never adds an endpoint.
 *
 * It is a thin composition of the already-generic {@see LifecycleService} plus subject resolution
 * and per-record guard context ({@see ProvidesGuardContext}) — the two things a host, not the
 * package, must supply.
 */
class WorkflowActuator
{
    public function __construct(
        protected SubjectResolverRegistry $resolvers,
        protected LifecycleService $lifecycle,
    ) {}

    /**
     * Resolve a `kind`+id to its managed model (or null).
     */
    public function subject(string $kind, string $id): ?Model
    {
        return $this->resolvers->resolve($kind, $id);
    }

    /**
     * The definition projection for a model (places, transitions, current marking, available
     * transitions), or `null` if unmanaged.
     *
     * @return array<string, mixed>|null
     */
    public function projection(Model $model): ?array
    {
        return $this->lifecycle->projection($model, $this->context($model));
    }

    /**
     * The available transitions for a model right now.
     *
     * @return list<string>
     */
    public function available(Model $model): array
    {
        return $this->lifecycle->available($model, $this->context($model));
    }

    /**
     * Apply a transition by name, pulling the model's own guard context. The host passes a
     * {@see TransitionContext} carrying the opaque actor token (e.g. `user:42`) it stamped from its
     * own auth — the package never reads `Auth::user()` (identity co-location).
     */
    public function transition(Model $model, string $name, ?TransitionContext $context = null): TransitionResult
    {
        return $this->lifecycle->transition($model, $name, $this->context($model), $context);
    }

    public function manages(Model $model): bool
    {
        return $this->lifecycle->manages($model);
    }

    /**
     * The per-record guard context — from the model when it declares one, else empty. Binding params
     * are merged separately by the LifecycleService.
     *
     * @return array<string, mixed>
     */
    protected function context(Model $model): array
    {
        return $model instanceof ProvidesGuardContext ? $model->workflowGuardContext() : [];
    }
}
