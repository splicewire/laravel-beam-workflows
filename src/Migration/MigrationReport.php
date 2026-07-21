<?php

namespace Splicewire\Beam\Workflows\Migration;

/**
 * The outcome of a marking-migration run (PRD v2 §3 / ticket 06). A migration is ALL-OR-NOTHING per
 * run: either every live marking in the cohort maps to the target version and the whole cohort
 * re-pins, or the run aborts having written nothing and this report says exactly why (which objects,
 * which un-mappable places).
 *
 *   - `applied`      true only when the cohort was actually re-pinned (never true for a dry run or an
 *                    aborted run).
 *   - `dryRun`       whether this was a preview (writes nothing regardless of mappability).
 *   - `total`        objects considered.
 *   - `migrated`     objects that WOULD (dry run) or DID (applied) move.
 *   - `unmappable`   the blockers: `{id, place}` for every object whose current place has no mapping
 *                    to the target version. Non-empty ⇒ the run aborted, `applied` is false.
 */
readonly class MigrationReport
{
    /**
     * @param  list<array{id: string, place: string}>  $unmappable
     */
    public function __construct(
        public string $lineageKey,
        public string $fromVersionId,
        public string $toVersionId,
        public int $total,
        public int $migrated,
        public array $unmappable,
        public bool $applied,
        public bool $dryRun,
    ) {}

    /**
     * Whether every marking in the cohort is mappable (no blockers). A dry run can be `ok()` without
     * being `applied`.
     */
    public function ok(): bool
    {
        return $this->unmappable === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lineageKey' => $this->lineageKey,
            'fromVersionId' => $this->fromVersionId,
            'toVersionId' => $this->toVersionId,
            'total' => $this->total,
            'migrated' => $this->migrated,
            'unmappable' => $this->unmappable,
            'applied' => $this->applied,
            'dryRun' => $this->dryRun,
        ];
    }
}
