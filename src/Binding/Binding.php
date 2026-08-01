<?php

namespace Splicewire\Beam\Workflows\Binding;

/**
 * One entry in the {@see WorkflowBindingRegistry}: the seam that attaches a workflow to a *kind of
 * thing* (PRD v2 §2). A binding says "objects of type `$typeKey` are governed by the definition
 * lineage `$lineageRef`, parameterised by `$params`."
 *
 *   - `typeKey`    the workflow-type key this binding governs (the resolver's selector, ticket 01).
 *   - `lineageRef` a reference to the definition lineage whose *active* version governs NEW objects
 *                  of this type (ticket 03). Today that reference is a registered blueprint name
 *                  (`composition.lifecycle`); once the lineage store lands it becomes a lineage id,
 *                  with no change to this shape.
 *   - `params`     a bag fed to the named guards at decision time — the generalisation of v1's
 *                  `require_review` env flag, which stops being a special case and becomes one
 *                  parameter among many.
 */
class Binding
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $typeKey,
        public string $lineageRef,
        public array $params = [],
    ) {}

    /**
     * Read one binding parameter (with a default) — the guard-context source that replaces reading
     * `config()` directly.
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * @return array{typeKey: string, lineageRef: string, params: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'typeKey' => $this->typeKey,
            'lineageRef' => $this->lineageRef,
            'params' => $this->params,
        ];
    }
}
