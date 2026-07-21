<?php

namespace Splicewire\Beam\Workflows\Definition;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A definition LINEAGE (PRD v2 §3): a stable identity that owns an ordered series of immutable
 * {@see WorkflowDefinitionVersion}s. A binding (ticket 02) points at a lineage — via its `key` —
 * and resolution picks the lineage's *active* version for NEW objects; existing objects stay pinned
 * to whatever version they started on.
 *
 * `is_system` marks a seeded code-registered default (e.g. `composition.lifecycle`). A tenant may
 * adopt the system default as-is or FORK it into a tenant-owned lineage/version and edit that — the
 * system default is the floor, the tenant's edit is a profile over it
 * (`tenant-is-a-profile-over-a-floor`).
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property bool $is_system
 */
class WorkflowDefinitionLineage extends Model
{
    use HasUuids;

    protected $table = 'workflow_definition_lineages';

    protected $guarded = [];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowDefinitionVersion::class, 'lineage_id');
    }

    /**
     * The active version — the one NEW objects of a bound type start on. Null if the lineage somehow
     * has no active pointer (a half-built lineage), which resolution treats as unresolvable.
     */
    public function activeVersion(): ?WorkflowDefinitionVersion
    {
        return $this->versions()->where('is_active', true)->orderByDesc('version')->first();
    }

    public function latestVersionNumber(): int
    {
        return (int) $this->versions()->max('version');
    }
}
