<?php

namespace Splicewire\Beam\Workflows\Migration;

use BackedEnum;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Display\State;
use Splicewire\Beam\Workflows\Display\StatusEmitter;
use Splicewire\Beam\Workflows\Display\StatusEvent;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/**
 * The ONLY sanctioned way a live object moves from one definition version to a newer one (PRD v2 §3
 * — never automatic). Given a lineage, a from/to version, a place-remap (`oldPlace → newPlace`), and
 * a cohort of objects pinned to the from version, it:
 *
 *   1. VALIDATES every object's current place has a mapping AND every mapped target place is declared
 *      in the target version's blueprint. Any object whose place can't be mapped becomes a blocker.
 *   2. If there are ANY blockers → aborts, writes nothing, returns a {@see MigrationReport} listing
 *      the offending objects/places. All-or-nothing per run — never a silent partial drop.
 *   3. Otherwise (and not a dry run) → inside ONE transaction, remaps each object's status place and
 *      re-pins it to the target version, emitting a Display event per object.
 *
 * The migrator is model-blind: the HOST supplies the cohort (e.g. `Composition::where(
 * 'workflow_version', $fromId)->get()`), so the package needs no type→model registry. An artisan
 * command / UI action is a thin caller over this service.
 *
 * ITS 1:1 `oldPlace => newPlace` MAP IS CORRECT, NOT A TRUNCATION. A cohort member's marking is read
 * out of a scalar status attribute and written back to one, because that is the whole of what a
 * lifecycle can persist (see {@see \Splicewire\Beam\Workflows\Control\LifecycleService::unpersistable()}).
 * There is therefore never a multi-place marking on this path to lose: an object could only hold one
 * if something had already written it, and the lifecycle refuses to. A place a map does not cover
 * blocks the WHOLE run rather than being silently skipped — the opposite failure mode from the one
 * `project()` used to have — so this service already fails loudly by construction.
 */
class MarkingMigrator
{
    public function __construct(
        protected DefinitionStore $store,
        protected StatusEmitter $emitter,
        protected ConnectionResolverInterface $db,
    ) {}

    /**
     * @param  array<string, string>  $placeMap  oldPlace => newPlace
     * @param  iterable<Model>  $cohort  objects pinned to $fromVersionId
     */
    public function migrate(
        string $lineageKey,
        string $fromVersionId,
        string $toVersionId,
        array $placeMap,
        iterable $cohort,
        bool $dryRun = false,
        ?string $runId = null,
    ): MigrationReport {
        $target = $this->store->version($toVersionId);
        if ($target === null || $target->lineage_id !== $this->store->lineageByKey($lineageKey)?->id) {
            throw new InvalidArgumentException("Target version [{$toVersionId}] is not a version of lineage [{$lineageKey}].");
        }

        $targetPlaces = array_flip($target->toBlueprint()->places);

        // A place-map that points at a place the target version doesn't declare is a bad map — reject
        // up front rather than migrate objects into an undeclared place.
        foreach ($placeMap as $old => $new) {
            if (! isset($targetPlaces[$new])) {
                throw new InvalidArgumentException("Place map sends [{$old}] to [{$new}], which the target version does not declare.");
            }
        }

        /** @var list<Model> $objects */
        $objects = is_array($cohort) ? array_values($cohort) : iterator_to_array($cohort, false);

        $unmappable = [];
        foreach ($objects as $object) {
            $place = $this->place($object);
            if (! array_key_exists($place, $placeMap)) {
                $unmappable[] = ['id' => (string) $object->getKey(), 'place' => $place];
            }
        }

        $total = count($objects);

        // Abort: any un-mappable marking blocks the WHOLE run — nothing is written.
        if ($unmappable !== []) {
            return new MigrationReport($lineageKey, $fromVersionId, $toVersionId, $total, 0, $unmappable, applied: false, dryRun: $dryRun);
        }

        // Dry run: report what would migrate, write nothing.
        if ($dryRun) {
            return new MigrationReport($lineageKey, $fromVersionId, $toVersionId, $total, $total, [], applied: false, dryRun: true);
        }

        $this->db->connection()->transaction(function () use ($objects, $placeMap, $toVersionId, $runId) {
            foreach ($objects as $object) {
                $old = $this->place($object);
                $new = $placeMap[$old];

                $object->{$this->statusAttribute($object)} = $new;
                if ($object instanceof WorkflowManaged) {
                    $object->{$object->workflowVersionAttribute()} = $toVersionId;
                }
                $object->save();

                $this->emitter->emit(
                    $object,
                    StatusEvent::whole(State::Running, "migrated: {$old} → {$new} (version pinned to {$toVersionId})"),
                    $runId,
                );
            }
        });

        return new MigrationReport($lineageKey, $fromVersionId, $toVersionId, $total, $total, [], applied: true, dryRun: false);
    }

    protected function statusAttribute(Model $object): string
    {
        return $object instanceof WorkflowManaged ? $object->workflowStatusAttribute() : 'status';
    }

    protected function place(Model $object): string
    {
        $value = $object->{$this->statusAttribute($object)} ?? '';

        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
