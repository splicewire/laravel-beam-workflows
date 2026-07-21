<?php

namespace Splicewire\Beam\Workflows\Type;

use Splicewire\Beam\Workflows\Type\Contracts\HasSchemaType;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/**
 * The one door every object goes through to reach its workflow-type key (PRD v2 §1). This is the
 * socket's selector: given ANY object, answer "what kind of thing is this, for workflow purposes?"
 * as a stable string key — or `null` when it is nothing the workflow layer governs.
 *
 * Two resolution doors, checked in order, both landing on the SAME key namespace:
 *
 *   1. A model that implements {@see WorkflowManaged} → its declared `workflowType()`.
 *   2. A schema-driven record that exposes an `x-stud` type ({@see HasSchemaType}) → projected
 *      through the {@see SchemaTypeProjector} (a pure schema-type → workflow-key mapping).
 *
 * Anything else → `null`. That null IS the generic fallback: an object with no resolvable type has
 * no binding and is therefore unmanaged — today's behaviour for everything that isn't a Composition,
 * expressed as the absence of a key rather than a special case.
 */
class TypeIdentityResolver
{
    public function __construct(
        protected SchemaTypeProjector $projector,
    ) {}

    /**
     * Resolve an object to its workflow-type key, or `null` if it is unmanaged.
     */
    public function forObject(object $object): ?string
    {
        // First door: the object declares its own identity.
        if ($object instanceof WorkflowManaged) {
            $key = trim($object->workflowType());

            return $key !== '' ? $key : null;
        }

        // Second door: a schema-driven record projects its `x-stud` type into the same namespace.
        if ($object instanceof HasSchemaType) {
            $schemaType = $object->xStudType();

            return $schemaType !== null && $schemaType !== ''
                ? $this->projector->project($schemaType)
                : null;
        }

        return null;
    }

    /**
     * Whether an object resolves to any workflow type at all (i.e. is potentially managed — it is
     * actually managed only if a binding exists for its key, ticket 02).
     */
    public function isResolvable(object $object): bool
    {
        return $this->forObject($object) !== null;
    }

    public function projector(): SchemaTypeProjector
    {
        return $this->projector;
    }
}
