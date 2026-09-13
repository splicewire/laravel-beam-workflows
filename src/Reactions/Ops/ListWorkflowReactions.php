<?php

namespace Splicewire\Beam\Workflows\Reactions\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionListData;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionSubjectData;

#[ParticleOp(
    resource: 'workflow-reactions',
    name: 'list',
    kind: OperationKind::Read,
    ability: false,
    subject: NoSubject::class,
    input: WorkflowReactionSubjectData::class,
    output: WorkflowReactionListData::class,
)]
class ListWorkflowReactions
{
    public static function handle(?object $model, Request $request, mixed $actor): WorkflowReactionListData
    {
        // The declared `input:` has already been validated by the controller; hydrate it and do the work.
        $input = WorkflowReactionSubjectData::from($request->all());

        $context = ReactionOperationContext::current();
        $service = app(\Splicewire\Beam\Workflows\Reactions\WorkflowReactionService::class);

        return $service->forSubject($input->subjectKind, $input->subjectId, $context);
    }
}
