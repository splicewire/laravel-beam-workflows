<?php

namespace Splicewire\Beam\Workflows\Reactions\Contracts;

use Illuminate\Database\Eloquent\Model;

/** A host freezes its content-bearing aggregate while the transition transaction is open. */
interface WorkflowSubjectSnapshot
{
    /** @return array<string, mixed> */
    public function capture(Model $subject): array;
}
