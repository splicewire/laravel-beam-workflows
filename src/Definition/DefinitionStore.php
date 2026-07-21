<?php

namespace Splicewire\Beam\Workflows\Definition;

use Illuminate\Database\ConnectionResolverInterface;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

/**
 * The read/write door onto the versioned definition store (PRD v2 §3). Everything that creates,
 * forks, resolves, or seeds a definition goes through here, so the immutable-version invariant is
 * enforced in exactly one place:
 *
 *   - a NEW object of a bound type resolves to the lineage's *active* version ({@see activeVersion});
 *   - an EXISTING object resolves to its *pinned* version, by id ({@see version}) — never the latest;
 *   - editing a lineage {@see fork}s a new immutable version (optionally flipping active); it never
 *     rewrites an existing one (the model enforces that, but the store never even tries).
 *
 * A code-registered definition is {@see ensureSystemLineage}d as a seeded system default a tenant
 * may later fork.
 */
class DefinitionStore
{
    public function __construct(
        protected ConnectionResolverInterface $db,
    ) {}

    public function lineageByKey(string $key): ?WorkflowDefinitionLineage
    {
        return WorkflowDefinitionLineage::query()->where('key', $key)->first();
    }

    public function version(string $versionId): ?WorkflowDefinitionVersion
    {
        return WorkflowDefinitionVersion::query()->find($versionId);
    }

    /**
     * The active version of a lineage (by key) — what a NEW object starts pinned to.
     */
    public function activeVersion(string $lineageKey): ?WorkflowDefinitionVersion
    {
        return $this->lineageByKey($lineageKey)?->activeVersion();
    }

    /**
     * The blueprint a NEW object of this lineage is governed by (the active version's snapshot).
     */
    public function activeBlueprint(string $lineageKey): ?WorkflowBlueprint
    {
        return $this->activeVersion($lineageKey)?->toBlueprint();
    }

    /**
     * Create a fresh lineage with its first (active) version. The blueprint is frozen into version 1.
     */
    public function createLineage(
        string $key,
        string $name,
        WorkflowBlueprint $blueprint,
        bool $isSystem = false,
    ): WorkflowDefinitionLineage {
        return $this->connection()->transaction(function () use ($key, $name, $blueprint, $isSystem) {
            $lineage = WorkflowDefinitionLineage::query()->create([
                'key' => $key,
                'name' => $name,
                'is_system' => $isSystem,
            ]);

            $lineage->versions()->create([
                'version' => 1,
                'blueprint' => $blueprint->toArray(),
                'is_active' => true,
            ]);

            return $lineage->refresh();
        });
    }

    /**
     * Idempotently seed a code-registered system default. If the lineage key already exists it is
     * returned untouched (a tenant may have forked it — never clobber that); otherwise it is created
     * as a system lineage. This is how `composition.lifecycle` becomes a floor a tenant can adopt.
     */
    public function ensureSystemLineage(string $key, string $name, WorkflowBlueprint $blueprint): WorkflowDefinitionLineage
    {
        return $this->lineageByKey($key) ?? $this->createLineage($key, $name, $blueprint, isSystem: true);
    }

    /**
     * Fork a new immutable version onto an existing lineage — the ONLY way an "edit" reaches the
     * store. The new version is `latest + 1`; when `$activate` it becomes the active pointer (the old
     * active is demoted) so new objects pick it up, while every already-pinned object keeps computing
     * against its own frozen version. Never mutates an existing version row.
     */
    public function fork(string $lineageKey, WorkflowBlueprint $blueprint, bool $activate = true): WorkflowDefinitionVersion
    {
        $lineage = $this->lineageByKey($lineageKey);

        if ($lineage === null) {
            // No lineage yet: a fork of nothing is just the first version.
            return $this->createLineage($lineageKey, $blueprint->name, $blueprint)->activeVersion();
        }

        return $this->connection()->transaction(function () use ($lineage, $blueprint, $activate) {
            if ($activate) {
                $lineage->versions()->where('is_active', true)->update(['is_active' => false]);
            }

            return $lineage->versions()->create([
                'version' => $lineage->latestVersionNumber() + 1,
                'blueprint' => $blueprint->toArray(),
                'is_active' => $activate,
            ]);
        });
    }

    /**
     * Point a lineage's active version at an existing version id (adopt/rollback) without forking —
     * a pointer move, not history rewrite.
     */
    public function activateVersion(string $lineageKey, string $versionId): ?WorkflowDefinitionVersion
    {
        $lineage = $this->lineageByKey($lineageKey);
        $target = $lineage?->versions()->whereKey($versionId)->first();

        if ($lineage === null || $target === null) {
            return null;
        }

        return $this->connection()->transaction(function () use ($lineage, $target) {
            $lineage->versions()->where('is_active', true)->update(['is_active' => false]);
            $target->is_active = true;
            $target->save();

            return $target->refresh();
        });
    }

    protected function connection()
    {
        return $this->db->connection();
    }
}
