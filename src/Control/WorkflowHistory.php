<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class WorkflowHistory
{
    /** @return Collection<int, WorkflowTransitionFact> */
    public function forSubject(Model $subject): Collection
    {
        return WorkflowTransitionFact::on($subject->getConnectionName())
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->orderBy('occurred_at')->orderBy('id')->get();
    }
}
