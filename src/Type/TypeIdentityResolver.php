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
     * Resolve an object to its PRIMARY (most specific) workflow-type key, or `null` if it is
     * unmanaged. This is the first of {@see candidatesFor()} — a schema identity outranks the class
     * identity, so a schema-driven record keys off its schema.
     */
    public function forObject(object $object): ?string
    {
        return $this->candidatesFor($object)[0] ?? null;
    }

    /**
     * The ordered candidate type keys for an object, MOST SPECIFIC FIRST. An object may resolve to
     * more than one identity — a schema-driven record that is also a model class projects through
     * BOTH doors — and the binding registry (ticket 02) picks the first candidate that actually has
     * a binding. This is what lets a specific schema be governed by its own workflow while every
     * other record of the same class falls back to the class-level workflow.
     *
     *   1. Schema door (specific): a `x-stud` schema type → its workflow-type key (via the projector).
     *   2. Model door (generic fallback): the class's declared `workflowType()`.
     *
     * @return list<string>
     */
    public function candidatesFor(object $object): array
    {
        $candidates = [];

        // Schema door first — a schema-driven record's schema identity is the more specific key.
        if ($object instanceof HasSchemaType) {
            $schemaType = $object->xStudType();
            if ($schemaType !== null && $schemaType !== '') {
                $projected = $this->projector->project($schemaType);
                if ($projected !== null && $projected !== '') {
                    $candidates[] = $projected;
                }
            }
        }

        // Model door — the class identity, as a fallback beneath any schema-specific key.
        if ($object instanceof WorkflowManaged) {
            $key = trim($object->workflowType());
            if ($key !== '') {
                $candidates[] = $key;
            }
        }

        return array_values(array_unique($candidates));
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
