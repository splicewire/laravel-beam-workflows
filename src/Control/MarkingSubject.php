<?php

namespace Splicewire\Beam\Workflows\Control;

/**
 * The throwaway in-memory subject the node hydrates from the marking that arrived in its input
 * port (the spike's crux: symfony/workflow's `MarkingStoreInterface` reads/writes a marking on an
 * arbitrary subject — here that subject is NOT a database row, it is this object).
 *
 * `marking` is the workflow-net representation `[place => tokens]` the `MethodMarkingStore`
 * (singleState:false) expects. `context` carries whatever a guard needs to decide (e.g. the cell
 * states behind a "no `Stale` cells" gate) — passed in through the port, never fetched, so the
 * node stays stateless across a pause.
 */
class MarkingSubject
{
    /** @var array<string, int>|null */
    public ?array $marking = null;

    /** @var array<string, mixed> */
    public array $context = [];

    /**
     * @param  list<string>  $places  The current marking as a list of place names.
     * @param  array<string, mixed>  $context
     */
    public static function fromPlaces(array $places, array $context = []): self
    {
        $subject = new self;
        $subject->marking = array_fill_keys($places, 1);
        $subject->context = $context;

        return $subject;
    }

    /**
     * The current marking as a *list* of place names (workflow-net safe — several places at once).
     *
     * @return list<string>
     */
    public function places(): array
    {
        return array_keys($this->marking ?? []);
    }
}
