# Authorized workflow history

`WorkflowHistoryResources::mount()` exposes the declared `workflow-history.read` operation.
It reads committed `WorkflowTransitionFact` rows, independently of the display activity log.
The host binds `WorkflowActionContextProvider` to its current authenticated principal and tenant.
The operation resolves `subject_kind` and `subject_id` through `WorkflowActionService::authorize`
on the current connection before querying facts. As with reaction-management reads, an empty
transition name requests the host's subject-management policy; it does not attempt a transition.
The operation's `ability: false` delegates that gate explicitly to the resolved subject policy.

The input is `{subject_kind, subject_id, limit?, before?}`. Pages are newest first, default to
50 facts, and accept at most 100. `next_before` is the next page's transition ID cursor; a cursor
must belong to the same authorized subject. A fact includes its stable transition ID, transition,
from/to markings, actor, run ID, causation ID/path, UTC occurrence time, and definition version.
Neither the input nor the cursor can supply execution credentials.

```php
WorkflowHistoryResources::mount(); // Inside the host's authenticated workflow route group.
```

`WorkflowHistoryReadTest` verifies bounded pagination, subject isolation, all durable fact fields,
revoked access, and rejection of cross-subject cursors. This generic history API has no dependency
on calendars or Tower.
