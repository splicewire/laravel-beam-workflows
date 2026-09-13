# Persisting a subject transition from a Circuit

`workflow.subject-transition` uses `WorkflowActionService` to persist a managed subject's
named transition and its durable receipt. `workflow.apply` remains the independent calculator
whose multi-place marking travels in an envelope. A subject action's successful output is a
`workflow.subject-transition` envelope containing subject identity, marking, transition, applied,
blockers and transition identity.

`WorkflowSubjectInputData` declares authored `subject_kind`, `subject_id` and `transition`.
`WorkflowSubjectResultData` declares the result. The invocable's input/output port schemas derive
from these Data classes through the host-configured schema generator; editor configuration can
use the input schema. The host registers the invocable in `WorkflowInvocableRegistry`, binds its
execution provider, and declares its capability ability in the host's palette/operation manifest.
Subject authorization still runs through `WorkflowActionAuthority` at execution.

## Trusted execution and durable visits

Invocation arrays supply no authority. In particular, `_circuit.context`, `run_id`, `actor` and a
client-provided definition pin cannot authenticate this action. `WorkflowCircuitExecutionProvider`
supplies the host-verified current visit out of band. Without one, invocation fails closed.

A host must durably prepare the request using `WorkflowActionService::prepare()` before its first
execution, retaining the returned definition pin and execution provenance alongside the persisted
node visit. The execution identity identifies that exact visit (for example, the node-run UUID
whose record has a unique run/node/iteration coordinate). Persist this before attempting the
subject action. Resume reuses the saved identity, context and pinned request. It must not resolve
a new pin or assign the resuming reviewer's authority to the original visit.

`WorkflowCircuitExecutionScope` can be bound as the scoped execution provider. Enter it only at
the actual node dispatch boundary using trusted persisted records:

```php
$execution = new WorkflowCircuitExecution(
    identity: $persistedVisitId,
    request: $storedPreparedRequest,
    context: $storedHostContext,
    connection: $tenantConnection,
);

$output = $scope->run($execution, fn () => $dispatcher->dispatch($node, $inputs, $runContext));
```

The callback boundary restores the previous scope in `finally`, including nested executions and
exceptions. Scope the actual node call, not the whole graph: each node and each intentional loop
visit needs its own verified record. If the host uses before/after walk observer hooks instead,
it must retain that same per-visit boundary and clear scope when dispatch or the walk fails.

The invocable checks the authored subject/transition against the stored prepared request. A
configuration edit cannot substitute a new action into an old visit. Repeating a completed visit
returns its durable receipt even if the subject later changes or migrates definitions; it never
reapplies that visit. A deliberately new loop visit gets another identity and rechecks the current
subject against its prepared definition. Receipt replay is evidence of the original action, not
fresh authorization to perform another one.

## Refusal and recovery

A blocked action throws `WorkflowSubjectTransitionBlocked`, carrying the structured result.
The Circuit scheduler's existing exception path marks the node failed, exposes its blocker text
as the node error and skips success-dependent descendants. Host projection can read the exception's
result to retain structured refusal details. An `applied: false` envelope alone would incorrectly
let the generic scheduler complete the node and continue.

Operational failures propagate for the host's retry policy. After a crash between committed action
and node-result persistence, redispatch of the saved visit recovers the same receipt. A refusal is
terminal for that identity; an explicit new attempt needs a new identity. SQLite package tests
prove receipt replay and scheduler failure/skip behavior; the consuming host must also verify its
durable node storage, concurrent workers, pause/resume and editor/run projections.

## Scheduled Circuit handoff

A calendar handing off a whole Circuit is a separate adapter. The host must create the Circuit run
and durable schedule-to-run association in one tenant transaction, then dispatch that existing run
after commit. Recovery finds the same run rather than calling an entry point that creates another.
A queued handoff is not a completed Circuit; expose the linked run's actual running, paused,
completed or failed state. External work still requires its own idempotency/reconciliation policy.
This package's subject invocable does not itself implement that whole-run handoff.
