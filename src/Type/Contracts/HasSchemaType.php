<?php

namespace Splicewire\Beam\Workflows\Type\Contracts;

use Splicewire\Beam\Workflows\Type\SchemaTypeProjector;

/**
 * The optional hook a schema-driven record exposes so the {@see
 * \Splicewire\Beam\Workflows\Type\TypeIdentityResolver} can reach it through the SECOND door — the
 * `x-stud` schema type it carries. A record implementing this projects its schema type into the
 * workflow-type namespace via the {@see SchemaTypeProjector}
 * (a pure mapping — no new identity).
 *
 * A model that already implements {@see WorkflowManaged} never needs this: the first door wins.
 * This contract only matters for records that are schema-shaped, not class-shaped — the door the
 * PRD builds up front though only one (class-shaped) consumer exists today.
 */
interface HasSchemaType
{
    /**
     * The `x-stud` schema type this record is an instance of, or `null` if it carries none.
     */
    public function xStudType(): ?string;
}
