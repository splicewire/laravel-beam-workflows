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

A `local` popcorn `Invocable` (`workflow.apply`) registered into this package's own `workflow`
registry. It wraps a [`symfony/workflow`](https://symfony.com/doc/current/workflow.html)
definition and uses it purely as a transition/guard **calculator** — persistence stays the host's
job (the marking rides the node's typed port envelope). It is always registered, whether or not a
circuit engine is installed; whether a circuit node dispatches to it is the host's business.

## Seam C — composition lifecycle (first consumer)

A real `CompositionStatus` state machine (`draft → review → published`, plus republish/unpublish)
proving both seams end-to-end.

## The load-bearing invariant

**Control** (`symfony/workflow` marking, guards) is authoritative — it decides what transition is
legal. **Display** (the activity-log projection) is a derived, eventually-consistent, fire-and-forget
timeline. A UI-driven change to the Display shape can never break execution correctness, because
Control is owned by the workflow marking store, not the status timeline.

See `docs/adr/` in the app for the ratified Display-vs-Control decision.

## Registries

This package declares seven registries. The [workflows cluster page](docs/registries/beam.workflows.mdx) says
which one you reach for.

- [`beam.workflows.blueprints`](docs/registries/beam.workflows.blueprints.mdx): named state machines declared in code.
- [`beam.workflows.bindings`](docs/registries/beam.workflows.bindings.mdx): which definition governs which type.
- [`beam.workflows.guards`](docs/registries/beam.workflows.guards.mdx): the code that decides whether a transition may run.
- [`beam.workflows.effects`](docs/registries/beam.workflows.effects.mdx): reactions that run after a transition lands.
  The package registers the await effect.
- [`beam.workflows.subject-resolvers`](docs/registries/beam.workflows.subject-resolvers.mdx): one finder per kind, so
  the generic workflow endpoints can load any managed record.
- [`beam.workflows.types`](docs/registries/beam.workflows.types.mdx): the governable type keys the Workflows admin offers.
- [`workflow`](docs/registries/workflow.mdx): the state-machine capabilities a circuit node dispatches to. The package
  registers `workflow.apply` and the subject invocable.

It registers into registries it doesn't own:

- [`beam.install.steps`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.install.steps.mdx),
  owned by `splicewire/laravel-beam`: the install step that publishes the config and tenant migrations.
- [`beam.doctor.audits`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.doctor.audits.mdx),
  owned by `splicewire/laravel-beam`: the migrations audit and the advisory multi-place marking audit.
- [`beam.particle.operations`](https://github.com/splicewire/laravel-beam/blob/main/docs/registries/beam.particle.operations.mdx),
  owned by `splicewire/laravel-beam`: the workflow history and reaction operations, registered when the host calls
  `WorkflowHistoryResources::mount()` or `WorkflowReactionResources::mount()`.
- [`beam.calendars.action-handlers`](https://github.com/splicewire/laravel-beam-calendars/blob/main/docs/registries/beam.calendars.action-handlers.mdx),
  owned by `splicewire/laravel-beam-calendars`: the scheduled-transition action handler, only when that package is
  installed and the host hasn't registered its own.
