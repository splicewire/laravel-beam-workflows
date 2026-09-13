<?php

namespace Splicewire\Beam\Workflows\History\Ops;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionContextProvider;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Actions\WorkflowActionService;
use Splicewire\Beam\Workflows\Control\WorkflowTransitionFact;
use Splicewire\Beam\Workflows\History\Data\ReadWorkflowHistoryInputData;
use Splicewire\Beam\Workflows\History\Data\ReadWorkflowHistoryOutputData;
use Splicewire\Beam\Workflows\History\Data\WorkflowHistoryFactData;

#[ParticleOp(
    resource: 'workflow-history',
    name: 'read',
    kind: OperationKind::Read,
    ability: false,
    subject: NoSubject::class,
    input: ReadWorkflowHistoryInputData::class,
    output: ReadWorkflowHistoryOutputData::class,
)]
class ReadWorkflowHistory
{
    public static function handle(?object $model, Request $request, mixed $actor): ReadWorkflowHistoryOutputData
    {
        $input = ReadWorkflowHistoryInputData::from($request->all());
        if ($input->limit < 1 || $input->limit > 100) {
            throw ValidationException::withMessages(['limit' => 'Choose a history page size between 1 and 100.']);
        }
        // The subject is an authored reference, so its host policy is checked after resolution.
        $context = app(WorkflowActionContextProvider::class)->current();
        $subject = app(WorkflowActionService::class)->authorize(
            new WorkflowActionData($input->subjectKind, $input->subjectId, ''), $context, app('db')->connection());
        $query = WorkflowTransitionFact::on($subject->getConnectionName())
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', (string) $subject->getKey());
        if ($input->before !== null) {
            $cursor = (clone $query)->whereKey($input->before)->firstOrFail();
            $query->where(function ($query) use ($cursor) {
                $query->where('occurred_at', '<', $cursor->getRawOriginal('occurred_at'))
                    ->orWhere(function ($query) use ($cursor) {
                        $query->where('occurred_at', $cursor->getRawOriginal('occurred_at'))->where('id', '<', $cursor->id);
                    });
            });
        }
        $facts = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit($input->limit + 1)->get();
        $more = $facts->count() > $input->limit;
        $page = $facts->take($input->limit);

        return new ReadWorkflowHistoryOutputData($page->map(fn ($fact) => WorkflowHistoryFactData::fromFact($fact))->values()->all(),
            $more ? (string) $page->last()->id : null);
    }
}
