<?php

namespace Splicewire\Beam\Workflows\Type;

/**
 * The SECOND resolution door (PRD v2 §1, `tenant-is-a-profile-over-a-floor`): a schema-driven
 * record has no PHP class to implement {@see Contracts\WorkflowManaged}, yet it must resolve into
 * the SAME workflow-type namespace as a model class. It does so by projecting its `x-stud` schema
 * type into a workflow-type key.
 *
 * This projection is a PURE MAPPING — schema type → workflow type key — and nothing more. It coins
 * no new identity; it is the line at which the schema world and the model world meet on one key
 * space, so the binding registry stays keyed by a single door.
 *
 * STUB (ticket 01): there is exactly one consumer today (Composition, a model), so no schema type
 * is wired yet. The class exists so the door is *built*, not deferred — a schema consumer lands by
 * registering one mapping here, with zero change to the resolver or the registry. By default a
 * schema type projects to an identically-named workflow key (identity mapping) unless a mapping
 * overrides it; an unmapped type that is explicitly disowned returns `null` ⇒ unmanaged.
 */
class SchemaTypeProjector
{
    /** @var array<string, string|null> Explicit schema-type → workflow-key overrides. */
    protected array $map = [];

    /** Whether an unregistered schema type projects to itself (identity) or to null (unmanaged). */
    protected bool $identityByDefault;

    public function __construct(bool $identityByDefault = false)
    {
        $this->identityByDefault = $identityByDefault;
    }

    /**
     * Map an `x-stud` schema type onto a workflow-type key (or `null` to explicitly disown it).
     */
    public function map(string $schemaType, ?string $workflowType): static
    {
        $this->map[$schemaType] = $workflowType;

        return $this;
    }

    /**
     * Project an `x-stud` schema type into a workflow-type key, or `null` if it maps to no workflow
     * type (⇒ the generic unmanaged fallback).
     */
    public function project(string $schemaType): ?string
    {
        if (array_key_exists($schemaType, $this->map)) {
            return $this->map[$schemaType];
        }

        return $this->identityByDefault ? $schemaType : null;
    }
}
