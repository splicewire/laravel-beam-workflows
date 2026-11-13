# Workflow API root

`WorkflowsRoutes::reactions()` and `WorkflowsRoutes::history()` are independent optional exports at `beam.workflows.api_root` (default `beam/workflows`, environment override `BEAM_WORKFLOWS_API_ROOT`). They use `reactions/` and `history/` subpaths and the existing resource-specific name stems under `beam.workflows.*`. There is no aggregate that opts a consumer into the other capability.

Authorization is unchanged: these operations declare `ability: false` and delegate to the resolved subject policy through the host's `WorkflowActionAuthority` and `WorkflowActionContextProvider` bindings. Supply those bindings and call the exports inside the authenticated tenant group. See [workflow history](workflow-history.md) and [required reactions](required-reactions.md). Workflow administration and subject controllers belong to Tower's separate export.

Keep the legacy host mounts during expansion. Host composition, URL policies and generated clients migrate separately. Reserve `reactions` and `history` beneath this root when composing a workflow administration route with a `{lineageKey}` segment. The Beam ApiRoot contract is ADR-0243, available from c583da31; the landing owner resolves that prerequisite. No provider automatically mounts the new exports.
