# laravel-beam-workflows

The **workflows arm** of the schemastud Beam family: _a beam can model status._

This is a free-tier Beam **veneer** (ADR-0092) that sets the platform precedent for workflows. It
sits **beside** the paid `laravel-circuit-engine` / `laravel-composition-engine` kernels and never
modifies either — honoring ADR-0034's "no shared executor" boundary. The only shared surface is
the `State` / `NeedsReview` **vocabulary**, not a resume loop.

It delivers two seams and one proving consumer, split along NOTES.md's **Display vs. Control**
line:

## Seam A — Display substrate (a normalized status projection)

One shared `State` enum (`queued | running | complete | failed | skipped`) and one
`StatusEvent { ref, state, message, progress, at }` value object. Producers **emit** a status
event; they never store status into the artifact they generate. Events project into the
already-installed [`spatie/laravel-activitylog`](https://github.com/spatie/laravel-activitylog) as
the timeline read-model:

| StatusEvent          | activity_log column          |
| -------------------- | ---------------------------- |
| `message`            | `description`                |
| `ref`/`state`/`progress` | `properties`             |
| `at`                 | `created_at`                 |
| run grouping         | `batch_uuid`                 |
| `status` channel     | `log_name`                   |

A single broadcast listener on emit ends the UI's poll loops.

## Seam B — state-machine Circuit node type (Control)

A `local` popcorn `Invocable` (`workflow.apply`) registered via the Circuit kernel's
`CapabilityManifest`. It wraps a [`symfony/workflow`](https://symfony.com/doc/current/workflow.html)
definition and uses it purely as a transition/guard **calculator** — persistence stays the host's
job (the marking rides the node's typed port envelope). Registered only when the circuit-engine is
installed; the Display substrate works without it.

## Seam C — composition lifecycle (first consumer)

A real `CompositionStatus` state machine (`draft → review → published`, plus republish/unpublish)
proving both seams end-to-end.

## The load-bearing invariant

**Control** (`symfony/workflow` marking, guards) is authoritative — it decides what transition is
legal. **Display** (the activity-log projection) is a derived, eventually-consistent, fire-and-forget
timeline. A UI-driven change to the Display shape can never break execution correctness, because
Control is owned by the workflow marking store, not the status timeline.

See `docs/adr/` in the app for the ratified Display-vs-Control decision.
