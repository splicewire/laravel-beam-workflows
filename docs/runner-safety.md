# Workflow runner: guards and status

`WorkflowRunner` is shared by in-memory Circuit workflow nodes and persisted subject
lifecycles. It computes legal transitions; the lifecycle owns persistence and transaction
boundaries.

Guards belong to individual transitions. Two transitions may both be named `publish`,
with `draft → published` guarded and `review → published` unguarded. The runner resolves
the guard from the exact Symfony Transition object's definition metadata, so one path
cannot inherit another path's guard. An unregistered declared guard blocks the move.
An exception from a guard deciding the requested move propagates without applying it.

By default, `apply()` projects the resulting marking onto Display. A persistence owner
can call `apply(..., emitStatus: false)`, commit its state, and then call
`emitStatus($blueprint, $markingSubject, $transition, $statusSubject, $context)`. The
marking subject must represent the successfully applied marking. The optional trailing
`markingProperty` argument supports subjects whose marking property has another name.
Neither method saves the subject.

Status projection is best effort. Its entire computation, including next-step guard
checks for Running/Complete, is inside the display exception boundary. Projection or
transport failures are reported and do not turn an applied transition into a failure.
The runner disables Symfony's announce phase because it has no announce subscribers and
that phase repeats next-step guard evaluation outside the display boundary. Explicit
`enabled()` calls still expose guard exceptions to their caller.

`tests/Control/WorkflowRunnerSafetyTest.php` exercises these behaviors through the runner
with registered blueprints. Existing Circuit node tests cover the default emission path
through capability dispatch.
