<?php

namespace Splicewire\Beam\Workflows\Control;

use InvalidArgumentException;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

/**
 * A name → {@see WorkflowBlueprint} registry, so a node's config (or a lifecycle service) can name
 * a definition — `composition.lifecycle` — instead of carrying the whole blueprint inline. The one
 * place a host declares its workflows, shared by the Seam B node and the Seam C lifecycle so both
 * drive the SAME definition.
 */
class WorkflowRegistry
{
    /** @var array<string, WorkflowBlueprint> */
    protected array $blueprints = [];

    /**
     * @param  WorkflowBlueprint|array<string, mixed>  $blueprint
     */
    public function register(string $name, WorkflowBlueprint|array $blueprint): static
    {
        $this->blueprints[$name] = $blueprint instanceof WorkflowBlueprint
            ? $blueprint
            : WorkflowBlueprint::fromArray($blueprint);

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->blueprints[$name]);
    }

    public function get(string $name): WorkflowBlueprint
    {
        return $this->blueprints[$name]
            ?? throw new InvalidArgumentException("No workflow blueprint registered under [{$name}].");
    }
}
