<?php

namespace Splicewire\Beam\Workflows\Reactions\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionRecordData;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionRevisionData;

#[ParticleOp(
    resource: 'workflow-reactions',
    name: 'disable',
    kind: OperationKind::Write,
    ability: false,
    subject: NoSubject::class,
    input: WorkflowReactionRevisionData::class,
    output: WorkflowReactionRecordData::class,
)]
class DisableWorkflowReaction
{
    public static function handle(?object $model, Request $request, mixed $actor): WorkflowReactionRecordData
    {
        // The declared `input:` has already been validated by the controller; hydrate it and do the work.
        $input = WorkflowReactionRevisionData::from($request->all());

        $context = ReactionOperationContext::current();
        $service = app(\Splicewire\Beam\Workflows\Reactions\WorkflowReactionService::class);
        $service->disable($input->id, $input->expectedRevision, $context);

        return $service->read($input->id, $context);
    }
}
