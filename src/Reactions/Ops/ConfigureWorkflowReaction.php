<?php

namespace Splicewire\Beam\Workflows\Reactions\Ops;

use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionData;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionRecordData;

#[ParticleOp(
    resource: 'workflow-reactions',
    name: 'configure',
    kind: OperationKind::Write,
    ability: false,
    subject: NoSubject::class,
    input: WorkflowReactionData::class,
    output: WorkflowReactionRecordData::class,
)]
class ConfigureWorkflowReaction
{
    public static function handle(?object $model, Request $request, mixed $actor): WorkflowReactionRecordData
    {
        // The declared `input:` has already been validated by the controller; hydrate it and do the work.
        $input = WorkflowReactionData::from($request->all());

        $context = ReactionOperationContext::current();
        $service = app(\Splicewire\Beam\Workflows\Reactions\WorkflowReactionService::class);

        return $service->read($service->configure($input, $context), $context);
    }
}
