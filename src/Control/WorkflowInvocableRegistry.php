<?php

namespace Splicewire\Beam\Workflows\Control;

use Rushing\Popcorn\Contracts\Invocable;
use Rushing\Popcorn\InvocableRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;

/**
 * Workflows' own branch — `workflow.apply` and anything a host adds beside it.
 *
 * ## Owning a root is what retires the soft-dependency guard
 *
 * This capability used to be registered behind
 * `class_exists(InvocableRegistry::class) && $this->app->bound(...)`, because it was being written
 * into the CIRCUIT engine's pool and a host wanting only the Display substrate boots without circuits.
 * That guard is registry-kernel ticket 04 D1's defect class: a package's registration silently
 * present or absent depending on what else the host installed, which is exactly what stops a
 * conformance gate ever being deterministic.
 *
 * It also was not really about circuits. `InvocableRegistry` ships in `rushing/php-popcorn`, which
 * this package requires outright — the bound() half was the real test, and it was testing whether
 * SOMEONE ELSE had bound a shared singleton. Registering into a registry this package owns removes
 * the question: the capability is always registered, and whether any circuit node dispatches to it is
 * the host's business rather than a condition on its existence.
 */
#[IsRegistry(
    root: 'workflow',
    entryType: Invocable::class,
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'workflow capabilities — the state-machine apply node, dispatched as a popcorn Invocable. The capability NAME is host-configurable (`beam.workflows.node_capability`). A host that renames it outside this root has the root stamped back on, because keys go relative in and absolute out — a rename moves the leaf, never the branch.',
)]
class WorkflowInvocableRegistry extends InvocableRegistry {}
