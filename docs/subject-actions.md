# Durable managed-subject actions

`WorkflowActionService` is the shared mutation path for optional calendar actions and persisted
Circuit node visits. It does not import Tower, Composition models or a tenant identity provider.
Hosts bind `WorkflowActionAuthority` to their current tenant, membership and subject policy checks.
An actor string is provenance; it grants no authority by itself.

Prepare an authored `WorkflowActionData` through `prepare()`. The service resolves and locks the
subject, checks authority, pins its effective workflow definition and validates the requested
transition name. It does not require the transition to be enabled today: a future action may be
authored while the subject is in an earlier state. Client-supplied version pins are ignored.

Execute the prepared request with a durable identity and `WorkflowActionContext`. A receipt, the
locked subject mutation and its required transition fact share the same database transaction and
connection. A repeated identity with the same request returns its original result. Reusing an
identity with different data is refused. A changed definition pin requires explicit rescheduling;
execution never silently migrates an authored action. Every new intentional retry or Circuit visit
needs a new identity. Completed receipt replay does not repeat mutation or effects.

The authority is checked again under the subject lock. Missing subjects, revoked authority,
changed pins and lifecycle blockers return an unapplied result. Operational exceptions roll back
all local writes. The contract covers local database consequences; an external message, provider
call or publication still needs its own durable handoff and recovery protocol.

## Optional calendar adapter

Install `splicewire/laravel-beam-calendars`, publish its action migrations and this package's
`workflow_action_receipts` and `workflow_transition_facts` migrations. Bind both the workflow
authority and the calendar `ActionContextProvider`. Mount `ActionResources` in the host's
authenticated scope. The workflow provider registers `kind.workflow-transition` in the neutral
handler registry only when a host has not registered an override.

The kind payload is declared by `WorkflowActionData`: `subject_kind`, `subject_id`, `transition`
and the server-owned `definition_version`. Timing, timezone, creator, principal, revision,
attempt history, cancellation and explicit retry belong to calendars. A host can extend the
handler to authorize an associated calendar; the neutral `calendar_id` is an opaque association,
not a Composition foreign key. A standalone calendar needs no Tower or composition dependency.

`composer test` includes the real optional providers and published migration stubs. It verifies
prepare/execute, duplicate delivery, authority revocation, definition migration, rollback,
calendar due-time dispatch and persisted history. SQLite tests do not prove PostgreSQL row-lock
contention or process-crash recovery; those consuming-host gates remain separate evidence.

See [transactional lifecycles](transactional-lifecycles.md) for the required fact versus best-effort
after-commit reactions, and [Circuit subject actions](circuit-subject-actions.md) for trusted node
visits and the existing envelope-only `workflow.apply` calculator.
