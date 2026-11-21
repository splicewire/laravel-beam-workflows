# 0003 — Guards evaluate with host principal context

Status: accepted (agreements 11a candidate; 11b waits for its cross-review)
Date: 2026-11-14

## Context

TransitionContext already carries the host's opaque actor token into transition facts and Display.
Guards receive only a marking subject and its facts; availability and projection previously had no
identity input. A principal-sensitive guard therefore cannot give the same answer when listing moves
and attempting one. The editor already stores declared guard params in transition
metadata.guardParams, but the runtime projection omits them.

## Decision

Reuse TransitionContext, without adding a principal resolver or reading ambient Auth. Hosts pass it
explicitly to WorkflowActuator::available(), ::projection() and ::transition(); LifecycleService's
read methods take it after their existing params argument. A trusted host stamps the actor; user
input and authored params cannot supply it. The stateless Circuit node continues actorless.

A guard opts in by explicitly typing its second parameter as `?TransitionContext`; it may accept
three arguments: the existing subject, nullable TransitionContext, and the transition's declared
guard params. Other callables retain the original subject-only invocation, including native
callables and guards with unrelated optional or variadic parameters. The identity context is copied
for each invocation; a guard cannot rewrite the caller's context or the actor later recorded on the
transition. MarkingSubject carries that context beside its existing facts bag so enabled() and
refusal evaluation share it without ambient mutable runner state. The runner's existing enabled()
signature stays intact for existing overrides.

The guard, implemented in the service that owns the identities, resolves the opaque token. A
principal-sensitive guard must return a blocking verdict for a missing or unresolvable principal,
including actorless workers or schedules. Durable actions already carry their frozen
WorkflowActionContext principal into TransitionContext (WorkflowActionService::execute); they are
evaluated as that principal, not treated as actorless merely because they run later. The engine
neither guesses a user nor blocks legacy guards that need no principal. This ticket proves that
contract with a TEST guard; no agreement or assent is read and no agreement identity or refusal
payload is defined.

Declared params reach the guard separately from runtime facts and identity. Projection returns
nonempty declared params in the existing WorkflowTransitionData.metadata.guardParams slot. It does
not return the runtime facts bag, actor, run id or unrelated metadata. An empty params bag needs no
metadata addition, preserving existing projections. Definition storage and the editor's wire
vocabulary do not change.

## Consequences

A host that wants a principal-sensitive projection must pass the same trusted context as on its
attempt. Omitting it fails closed in that guard; it never falls back to a previous caller's actor.
Context remains host-owned and opaque, with no new authentication or scheduling port.

The added optional context arguments extend non-final actuator/lifecycle methods and runner guard
hooks. A host subclass overriding those signatures must update its override before consuming this
pre-1.0 change; enabled() retains its old signature. Post-apply Display computes terminal state
using the same marking subject and its context, so principal-sensitive next-step availability is
actor-relative; Display remains best effort and never authorizes execution.

Declared guard params are visible to runtime projection readers, not only editors. They must contain
non-secret configuration; runtime secrets belong in the host's resolution, never authored params.

Agreements 11b binds the real guard only after this decision and implementation have completed 11a's
fresh other-family cross-review. It also owns the upstream verdict, unknown-agreement validation and
accept step. The Agreement picker is unsettled and excluded from 11a.

Agreements 11b (lead ruling Q-11b-b) amends the registry for that validation: `GuardRegistry::register()`
takes an optional, named `validateParams` callable after `paramsSchema`, which `BlueprintValidator` runs
on each transition's authored `metadata.guardParams` at save, so a guard's owner rejects params naming
something that does not exist, against its own live state. A guard registered without one is checked
for membership only, exactly as before. The validator never reaches the catalog or the wire.

## Claude review and the amend round

Fresh Claude returned PROCEED on `5bef72c3..52833547`; the review record accompanies READY.
The author used one amend; it is not re-reviewed.

| Finding | Disposition and propagation |
|---|---|
| Schedules can carry a frozen principal | Clarified this ADR against WorkflowActionService::execute and ADR-0001: only missing/unresolvable principals block. No scheduling behavior changed. |
| Unrelated optional arguments could receive the new context | Fixed by explicit typed opt-in; a red-then-green actuator test covers optional/variadic legacy guards. Propagated to runner, registry contract, test, this ADR and guard guide (5 files). |
| Host subclass signatures need updating | Recorded the pre-1.0 consequence here; enabled() remains unchanged. |
| Declared params are visible to projection readers | Documented the non-secret requirement here and in the guard guide (2 files). |
| Display inherits actor-relative next-step availability | Clarified the best-effort consequence here; no new authorization or completion mechanism. |
