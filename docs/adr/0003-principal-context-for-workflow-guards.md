# 0003 — Guards evaluate with host principal context

Status: accepted (agreements 11a candidate; 11b waits for its cross-review)
Date: 2026-11-14

## Context

TransitionContext already carries the host's opaque actor token into transition facts and
Display. Guards receive only a marking subject and its facts; availability and projection
previously had no identity input. A principal-sensitive guard therefore cannot give the same
answer when listing moves and attempting one. The editor already stores declared guard params
in transition metadata.guardParams, but the runtime projection omits them.

## Decision

Reuse TransitionContext, without adding a principal resolver or reading ambient Auth. Hosts pass
it explicitly to WorkflowActuator::available(), ::projection() and ::transition(); LifecycleService's
read methods take it after their existing params argument. A trusted host stamps the actor; user
input and authored params cannot supply it. The stateless Circuit node continues actorless.

A guard may accept three arguments: the existing subject, nullable TransitionContext, and the
transition's declared guard params. Existing one-argument PHP guards still work. The identity
context is copied for each invocation; a guard cannot rewrite the caller's context or the actor
later recorded on the transition. MarkingSubject carries that context beside its existing facts
bag so enabled() and refusal evaluation share it without ambient mutable runner state. The
runner's existing enabled() signature stays intact for existing overrides.

The guard, implemented in the service that owns the identities, resolves the opaque token. A
principal-sensitive guard must return a blocking verdict for a missing or unresolvable principal,
including workers and schedules. The engine neither guesses a user nor blocks legacy guards
that need no principal. This ticket proves that contract with a TEST guard; no agreement or
assent is read and no agreement identity or refusal payload is defined.

Declared params reach the guard separately from runtime facts and identity. Projection returns
nonempty declared params in the existing WorkflowTransitionData.metadata.guardParams slot. It
does not return the runtime facts bag, actor, run id or unrelated metadata. An empty params bag
needs no metadata addition, preserving existing projections. Definition storage and the editor's
wire vocabulary do not change.

## Consequences

A host that wants a principal-sensitive projection must pass the same trusted context as on its
attempt. Omitting it fails closed in that guard; it never falls back to a previous caller's actor.
Context remains host-owned and opaque, with no new authentication or scheduling port.

Agreements 11b binds the real guard only after this decision and implementation have completed
11a's fresh other-family cross-review. It also owns the upstream verdict, unknown-agreement
validation and accept step. The Agreement picker is unsettled and excluded from 11a.
