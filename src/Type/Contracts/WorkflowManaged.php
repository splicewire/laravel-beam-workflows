<?php

namespace Splicewire\Beam\Workflows\Type\Contracts;

use Splicewire\Beam\Workflows\Type\TypeIdentityResolver;

/**
 * The socket's SELECTOR (PRD v2 §1, `the-seam-is-a-registry`): what makes an object resolvable to a
 * workflow. A model opts *in* to being governed by declaring three things — never more:
 *
 *   - `workflowType()`            the stable string key it resolves through (`composition`,
 *                                 `batch-run`, …). This is the ONE namespace both a model class and
 *                                 a schema `x-stud` type project into, so the binding registry
 *                                 (ticket 02) is keyed by a single door.
 *   - `workflowStatusAttribute()` the attribute its marking projects onto (default `status`).
 *   - `workflowVersionAttribute()` the attribute pinning the definition VERSION it started on
 *                                 (default `workflow_version`), so its guards/transitions always
 *                                 compute against its pinned graph, never the latest (ticket 03).
 *
 * Uniformity lives in the socket, not the plug: an object that does NOT implement this contract is
 * simply *unmanaged* — {@see TypeIdentityResolver} returns `null`
 * for it, which is exactly today's behaviour for everything that isn't a Composition. The generic
 * fallback is the absence of a binding, not a special case.
 *
 * The {@see \Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged} trait supplies the two
 * attribute defaults so a consuming model only has to name its type key.
 */
interface WorkflowManaged
{
    /**
     * The stable workflow-type key this object resolves through (the binding registry's selector).
     */
    public function workflowType(): string;

    /**
     * The attribute the authoritative marking projects onto (single-place lifecycle → the sole
     * place name). Defaulted to `status` by the trait.
     */
    public function workflowStatusAttribute(): string;

    /**
     * The attribute pinning the definition version this object is running under. Its transitions
     * compute against THIS version, never the lineage's active one. Defaulted to `workflow_version`
     * by the trait.
     */
    public function workflowVersionAttribute(): string;
}
