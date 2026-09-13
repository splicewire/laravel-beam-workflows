# Required workflow follow-ups

Configured follow-ups are recorded inside the successful subject transition's transaction. The
marking, transition fact, prepared request, source snapshot and delivery obligation commit together.
Refusal or rollback leaves no delivery. Display and named notification effects remain best effort
and do not carry required distribution or expiry obligations.

Bind `WorkflowActionAuthority` and `WorkflowActionContextProvider` to the host's real policy and
current authenticated tenant/principal. Register destination calendar handlers and mount
`WorkflowReactionResources::mount()` in the authenticated route group. Its declared configure,
list, disable and retry operations authorize the source subject. Configure also authorizes and
prepares the destination. Credential fields never come from authored input; destination execution
rechecks the recorded principal's current authority.

```php
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionData;
use Splicewire\Beam\Workflows\Reactions\WorkflowReactionService;
use Splicewire\Beam\Workflows\Reactions\WorkflowReactionDispatcher;
use Splicewire\Beam\Calendars\Actions\ActionScheduler;

$id = app(WorkflowReactionService::class)->configure(
    new WorkflowReactionData(
        subjectKind: 'article', subjectId: $articleId, transition: 'publish',
        actionKind: 'kind.workflow-transition',
        actionPayload: ['subject_kind' => 'article', 'subject_id' => $articleId, 'transition' => 'unpublish'],
        calendarDays: 30, timezone: 'America/New_York',
    ),
    $trustedContext,
);

// In the correct tenant/connection, after the source transaction:
app(WorkflowReactionDispatcher::class)->sweep($tenantToken);
app(ActionScheduler::class)->sweep($tenantToken);
```

Circuit destinations use an optional host/Tower handler. Generic capture and history have no
calendar dependency; configuring and delivering timed actions requires the optional calendar
package. Workflows never imports Composition or Tower.

Delivery identity combines the transition fact and binding. A crash before dispatcher commit leaves
a pending obligation; replay after commit finds the same action. The calendar attempt is a separate
execution boundary. Circuit acceptance records a local run handoff: actual run success or failure
must be read from the run/handoff. Publication remains successful when later distribution fails.
External side effects still need their own idempotency and reconciliation contract.

`WorkflowSubjectSnapshot` is a host port called inside source capture. The default freezes model
attributes, respecting hidden fields. Aggregate/version-aware hosts bind a richer implementation,
for example a full Composition version snapshot including cells and workflow pin. Delayed work
receives this stored artifact, never a fresh read of edited content. An internal causation envelope
carries source transition, snapshot and binding path; public handler preparation strips it.

Relative dates use the successful mutation timestamp retained by the committed transition fact,
not a planned date or dispatch time. This is not a database-engine commit timestamp. Calendar days
preserve local wall-clock time in the configured IANA timezone. Gaps move forward by the offset jump;
folds choose the earlier instant. The resolved instant and timezone stay on the action. The calendar
package owns this shared arithmetic.

Each capture increments a durable per-binding ordinal under lock. A newer publication supersedes
an older untouched pending expiry even when timestamps are equal or deliveries arrive backwards.
Replacement locks the same action row as firing. Database concurrency failures retry the whole
delivery transaction up to three times; exhaustion leaves the durable obligation pending for a
later sweep. Terminal history is immutable. Explicit pending
edits increment revision and detach that occurrence from replacement while preserving its source
link. Normal pending cancellation and explicit attempt retry remain available.

Disabling a binding stops future capture; existing committed obligations remain. Blocked/failed
delivery is visible with its source transition and can be explicitly retried after repairing the
handler or authority. Repeated binding identity or a causal path beyond 16 stops re-entry visibly;
a cycle cannot be retried unchanged. An action blocked by a changed workflow pin requires a newly
authored schedule: retry intentionally retains the previous pin and history.

Publish the facts, receipts and reactions tenant migrations before enabling upgraded lifecycles.
PostgreSQL existence predicates use `current_schema()` so a public fallback cannot hide a missing
tenant table. Required deliveries and facts share the actual subject connection.

Tests cover rollback/refusal, cycles, destination revocation, replay, actual-time DST anchoring,
equal-time/reverse-order cycles and manual detachment. Consuming checks add real PostgreSQL process
failure/row contention and mounted browser configuration/history. See
[the decision](adr/0001-required-workflow-reactions.md).
