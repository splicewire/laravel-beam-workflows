> You are in **splicewire/laravel-beam-workflows** — the workflows arm of the schemastud Beam family: a beam can model status.

A free-tier Beam veneer that sets the platform precedent for workflows. It ships a normalized
status projection (Display, over `spatie/laravel-activitylog`) and a state-machine Circuit node
type (Control, over `symfony/workflow`), sitting beside the paid `laravel-circuit-engine` /
`laravel-composition-engine` kernels without modifying either. Ships one shared `State` vocabulary
and `StatusEvent` shape and proves both seams end-to-end on the composition lifecycle.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
