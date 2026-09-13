# Transactional managed-subject lifecycles

`LifecycleService::transition()` locks an existing subject row on its own connection, refreshes its
stored attributes, and computes model-owned guard context inside that lock. Interactive callers,
calendar handlers and persisted Circuit actions must use this seam. Unsaved caller edits are not
transition inputs; save them deliberately before requesting the transition. A stale model cannot
restore an old marking or supply stale review facts.

The status mutation, definition pin, `workflow_transition_facts` insert and configured required
reaction capture share the subject's transaction. The returned `TransitionResult::transitionId` identifies that control fact. Rollback
removes both status change and fact; retry after rollback can make a new attempt. History is read
through `WorkflowHistory::forSubject()`, independently of activity-log retention or display failures.
Publish the new `create_workflow_transition_facts_table` tenant migration with
`vendor:publish --tag=beam-workflows-migrations` before using the upgraded lifecycle.

`WorkflowTransitioned`, status Display emissions and named notification effects run only after the
outer transaction commits. They remain best effort: failure is reported and never converts committed
success into a failed transition. Required downstream work uses [durable reaction deliveries](required-reactions.md),
independently of receiving those process-local callbacks. A process that dies after commit can lose a
notification; it cannot erase the fact. Facts carry actor, run, causation and causal-path fields;
these are provenance, not authorization credentials.

The recorded `occurred_at` is the application-clock mutation time (UTC, with microseconds), retained when that
transaction commits. It is the timestamp of the successful mutation, not the planned date or the time
a later consumer observes it. This is not a database-engine commit timestamp service.

`pinDefinition()` freezes a code-only blueprint into the existing versioned store and pins the
subject without applying a transition. Existing pins are preserved; an unresolvable existing pin
fails closed. Definition reads/writes use the subject's connection, including explicit connections.
Scheduling must hold that subject lock together with its own schedule insert, and must compare the
recorded pin again at execution. A future transition may be scheduled even when its source marking
has not been reached, but its name must exist in the pinned definition.

The package tests use process-isolated SQLite for rollback and stale-snapshot controls. Row locking
is effective on databases that support `SELECT ... FOR UPDATE`; multi-worker PostgreSQL evidence
belongs to the consuming schedule acceptance gate and is not established by SQLite tests.
