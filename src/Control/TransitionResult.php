<?php

namespace Splicewire\Beam\Workflows\Control;

/**
 * The observable outcome of attempting a transition (Control): the resulting marking (as a list of
 * places, workflow-net safe), which transition was attempted, whether it `applied`, and — when it
 * did not — the human-readable `blockers` (guard reasons / "not enabled"). This is what both the
 * node's output port and the Seam C lifecycle return, so a caller branches on `applied` without
 * catching exceptions.
 */
class TransitionResult
{
    /**
     * @param  list<string>  $marking
     * @param  list<string>  $blockers
     */
    public function __construct(
        public array $marking,
        public string $transition,
        public bool $applied,
        public array $blockers = [],
    ) {}

    /**
     * @return array{marking: list<string>, transition: string, applied: bool, blockers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'marking' => $this->marking,
            'transition' => $this->transition,
            'applied' => $this->applied,
            'blockers' => $this->blockers,
        ];
    }
}
