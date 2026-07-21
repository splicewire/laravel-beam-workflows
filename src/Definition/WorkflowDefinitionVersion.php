<?php

namespace Splicewire\Beam\Workflows\Definition;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

/**
 * One IMMUTABLE version of a definition lineage (PRD v2 §3): a frozen {@see WorkflowBlueprint}
 * snapshot with an ordered `version` number and an `is_active` pointer. This is the load-bearing
 * invariant that makes in-app authoring safe rather than a footgun:
 *
 *   > A definition is immutable once a live object is running under it. Editing produces a new
 *   > version; existing objects stay pinned to the version they started on until explicitly migrated.
 *
 * Immutability is enforced HERE, in the model: once a version row is persisted, its `blueprint`,
 * `version` and `lineage_id` can never change — an attempt throws. Only `is_active` (a pointer, not
 * history) may be flipped. So "editing a live definition" can only ever mean forking a NEW version;
 * the code has no path to rewrite places/transitions under objects mid-flight.
 *
 * Runs on the default connection — under the host's tenancy that is the tenant schema, so versions
 * are tenant data (§Multi-tenancy).
 *
 * @property string $id
 * @property string $lineage_id
 * @property int $version
 * @property array<string, mixed> $blueprint
 * @property bool $is_active
 */
class WorkflowDefinitionVersion extends Model
{
    use HasUuids;

    protected $table = 'workflow_definition_versions';

    protected $guarded = [];

    protected $casts = [
        'blueprint' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    /** The columns frozen once the row exists — history a fork must never rewrite. */
    protected const IMMUTABLE = ['lineage_id', 'version', 'blueprint'];

    protected static function booted(): void
    {
        // The immutability gate: reject any update that mutates a frozen column on an existing row.
        static::updating(function (self $version): void {
            foreach (self::IMMUTABLE as $column) {
                if ($version->isDirty($column)) {
                    throw new RuntimeException(
                        "Workflow definition version [{$version->id}] is immutable; column [{$column}] "
                        .'cannot be changed. Edit forks a new version instead of rewriting one.',
                    );
                }
            }
        });
    }

    public function lineage(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinitionLineage::class, 'lineage_id');
    }

    /**
     * Rehydrate the frozen snapshot into a {@see WorkflowBlueprint} — what the lifecycle computes
     * `can()`/guards/transitions against for an object pinned to THIS version.
     */
    public function toBlueprint(): WorkflowBlueprint
    {
        return WorkflowBlueprint::fromArray($this->blueprint);
    }
}
