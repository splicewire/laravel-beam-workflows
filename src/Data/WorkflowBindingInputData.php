<?php

namespace Splicewire\Beam\Workflows\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Example;
use Splicewire\Beam\Data\BeamData;

/**
 * Input payload for binding a type to a workflow (`PUT /beam/workflows/bindings`).
 *
 * The exemplar for the API-parameter convention (api-surface-coherence ticket 08): every documented
 * parameter is declared on a Data class and described where it is declared, never in a `@bodyParam`
 * docblock. This replaced an inline `$request->validate([...])` whose three fields reached the
 * reference as bare strings with no prose and faker examples (`ea`, `dicta`).
 *
 * Distinct from {@see WorkflowBindingData}, which is the *response* shape — same three fields, but a
 * record of what the binding IS rather than a description of what a caller may send.
 */
#[Description('Payload for putting a record type under the governance of a workflow. Binding a type that is already bound replaces the existing binding rather than adding a second.')]
class WorkflowBindingInputData extends BeamData
{
    public function __construct(
        #[Description('The record type to govern — a schema identifier. Sent in the body rather than the path because a schema `$id` may contain slashes.')]
        #[Example('https://schemas.splicewire.app/food-safety/restaurant/1')]
        public string $typeKey,

        #[Description('Which workflow governs the type, named by its lineage rather than a specific version, so the binding follows the workflow as it is revised. Must name a lineage that already exists.')]
        #[Example('food-safety-inspection')]
        public string $lineageRef,

        /**
         * Nullable rather than defaulted-to-`[]` so the generated schema types it `[array, null]` —
         * the only signal a reader gets that it is optional, since the request schema currently lists
         * every property in `required` regardless of nullability or default
         * (api-surface-coherence ticket 31). Read it as `$input->params ?? []`.
         *
         * @var array<string, mixed>|null
         */
        #[Description('Guard parameters passed to the workflow on every transition it evaluates for this type. Shape is the workflow\'s own; omit for workflows whose guards take no configuration.')]
        #[Example(['requiresManagerApproval' => true])]
        public ?array $params = null,
    ) {}

    /**
     * Carries the rules the controller previously declared inline. `lineageRef` is checked for
     * existence in the controller rather than here — resolving a lineage needs the DefinitionStore,
     * and that lookup stays where it can return the specific "no such lineage" message.
     */
    public static function rules(): array
    {
        return [
            'typeKey' => 'required|string',
            'lineageRef' => 'required|string',
            'params' => 'array',
        ];
    }
}
