<?php

namespace Splicewire\Beam\Workflows\Admin\Contracts;

/**
 * The host seam for the workflow-admin "which types can be governed?" question (beam-workflows v2).
 * The package knows the type keys that are *registered* ({@see \Splicewire\Beam\Workflows\Type\
 * WorkflowTypeRegistry}) and *bound*, but a host may have additional governable identities the
 * package can't enumerate — e.g. every registered schema (`$id`). A host implements this to feed
 * those into the binding dropdown, keeping the package model- and schema-registry-agnostic.
 */
interface GovernableTypeSource
{
    /**
     * Extra governable type options, most-relevant first.
     *
     * @return list<array{key: string, label: string}>
     */
    public function governableTypes(): array;
}
