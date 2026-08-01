<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Support\Facades\Auth;

/**
 * The host's actor-stamping seam for beam-workflows transitions (beam-workflows-ux ticket 05).
 *
 * The engine is opaque to identity (co-location invariant): it never reads `Auth::user()` and never
 * resolves the token. Instead the HOST — which owns the users — stamps an opaque `kind:selector` token
 * at each transition call site and hands it to the package in a {@see TransitionContext}. This is the
 * single place `Auth::id()` becomes a `user:<id>` token, so every HTTP transition path stamps it the
 * same way and a later principal→user resolver (tickets 11–14) has one grammar to parse.
 *
 * A system / queue / scheduled path has no authenticated user and passes `null` — self-exclusion then
 * subtracts nothing, which is correct (no human drove the move).
 */
class WorkflowActor
{
    /**
     * The opaque actor token for the current request's authenticated user, or null when unauthenticated
     * (a system/queue/scheduled path). Grammar: `user:<id>` — a `kind:selector` string the engine
     * forwards verbatim and the host resolves at read time.
     */
    public static function token(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : 'user:'.$id;
    }

    /**
     * A {@see TransitionContext} carrying the current user's actor token (and an optional run id),
     * ready to hand to `LifecycleService::transition()` / `WorkflowActuator::transition()`.
     */
    public static function context(?string $runId = null): TransitionContext
    {
        return new TransitionContext(actor: self::token(), runId: $runId);
    }
}
