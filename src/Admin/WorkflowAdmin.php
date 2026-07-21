<?php

namespace Splicewire\Beam\Workflows\Admin;

use Splicewire\Beam\Workflows\Admin\Contracts\GovernableTypeSource;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\BlueprintValidator;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Definition\WorkflowDefinitionLineage;
use Splicewire\Beam\Workflows\Definition\WorkflowDefinitionVersion;
use Splicewire\Beam\Workflows\Type\WorkflowTypeRegistry;

/**
 * The model-agnostic behaviour behind the workflow-admin surface (beam-workflows v2 — the seam
 * pass). Everything that is NOT transport or host-specific lives here, so any host gets the admin
 * for free by wiring thin controllers over it:
 *
 *   - the editor's {@see catalog()} (blueprint schema + guard catalog + governable types),
 *   - the lineage {@see lineages()} / {@see lineage()} read models,
 *   - {@see saveVersion()} (validate → fork an immutable version).
 *
 * What stays with the HOST (and is deliberately NOT here): HTTP + auth + the response envelope,
 * binding *persistence* (a tenant table), record *coverage* (a host model), and the extra
 * governable types a host enumerates ({@see GovernableTypeSource}). This service returns plain
 * arrays shaped to the host's DTOs — the wire contract stays the host's.
 */
class WorkflowAdmin
{
    public function __construct(
        protected DefinitionStore $store,
        protected GuardRegistry $guards,
        protected WorkflowBindingRegistry $bindings,
        protected BlueprintValidator $validator,
        protected WorkflowTypeRegistry $types,
    ) {}

    /**
     * The editor's contract: the blueprint JSON Schema, the guard catalog, and the governable type
     * options (registered types, then any host-supplied types, then already-bound types — first
     * write wins, so nothing is orphaned or duplicated).
     *
     * @return array{blueprintSchema: array<string, mixed>, guards: list<array{name: string, label: string, paramsSchema: array<string, mixed>}>, types: list<array{key: string, label: string}>}
     */
    public function catalog(?GovernableTypeSource $source = null): array
    {
        $options = [];
        foreach ($this->types->all() as $type) {
            $options[$type['key']] ??= $type;
        }
        foreach ($source?->governableTypes() ?? [] as $type) {
            $options[$type['key']] ??= $type;
        }
        foreach ($this->bindings->all() as $binding) {
            $options[$binding->typeKey] ??= ['key' => $binding->typeKey, 'label' => $binding->typeKey];
        }

        return [
            'blueprintSchema' => WorkflowBlueprint::jsonSchema(),
            'guards' => $this->guards->guardCatalog(),
            'types' => array_values($options),
        ];
    }

    /**
     * Every lineage with its versions + the types bound to it.
     *
     * @return list<array<string, mixed>>
     */
    public function lineages(): array
    {
        $bound = $this->boundByLineage();

        return WorkflowDefinitionLineage::query()
            ->with(['versions' => fn ($q) => $q->orderBy('version')])
            ->orderBy('key')
            ->get()
            ->map(fn (WorkflowDefinitionLineage $lineage) => $this->lineageArray($lineage, $bound))
            ->all();
    }

    /**
     * One lineage with its versions, or `null` if unknown.
     *
     * @return array<string, mixed>|null
     */
    public function lineage(string $key): ?array
    {
        $lineage = $this->store->lineageByKey($key)?->load('versions');

        return $lineage ? $this->lineageArray($lineage, $this->boundByLineage()) : null;
    }

    /**
     * Author a version: validate the posted blueprint (referential integrity + guard-catalog
     * membership) then FORK a new immutable version. Throws {@see \InvalidArgumentException} on an
     * invalid blueprint (the host maps that to a 422); never mutates an existing version.
     *
     * @param  array<string, mixed>  $blueprintData
     * @return array<string, mixed>
     */
    public function saveVersion(string $lineageKey, array $blueprintData, bool $activate = true): array
    {
        $blueprint = WorkflowBlueprint::fromArray($blueprintData);
        $this->validator->validate($blueprint);

        return $this->versionArray($this->store->fork($lineageKey, $blueprint, $activate));
    }

    /**
     * @return array<string, list<string>>
     */
    protected function boundByLineage(): array
    {
        $map = [];
        foreach ($this->bindings->all() as $binding) {
            $map[$binding->lineageRef][] = $binding->typeKey;
        }

        return $map;
    }

    /**
     * @param  array<string, list<string>>  $boundByLineage
     * @return array<string, mixed>
     */
    protected function lineageArray(WorkflowDefinitionLineage $lineage, array $boundByLineage): array
    {
        return [
            'key' => $lineage->key,
            'name' => $lineage->name,
            'isSystem' => $lineage->is_system,
            'boundTypes' => $boundByLineage[$lineage->key] ?? [],
            'versions' => $lineage->versions
                ->sortBy('version')
                ->map(fn (WorkflowDefinitionVersion $v) => $this->versionArray($v))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: string, version: int, isActive: bool, blueprint: array<string, mixed>}
     */
    protected function versionArray(WorkflowDefinitionVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'isActive' => $version->is_active,
            'blueprint' => $version->blueprint,
        ];
    }
}
