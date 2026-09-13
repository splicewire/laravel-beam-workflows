<?php

namespace Splicewire\Beam\Workflows\Reactions;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Workflows\Reactions\Contracts\WorkflowSubjectSnapshot;

/** Default for a simple managed row; hidden attributes are excluded. */
class AttributeWorkflowSubjectSnapshot implements WorkflowSubjectSnapshot
{
    public function capture(Model $subject): array
    {
        return $subject->attributesToArray();
    }
}
