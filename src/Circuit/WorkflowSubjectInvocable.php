<?php

namespace Splicewire\Beam\Workflows\Circuit;

use LogicException;
use ReflectionClass;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Workflows\Actions\WorkflowActionService;
use Splicewire\Beam\Workflows\Circuit\Contracts\WorkflowCircuitExecutionProvider;
use Splicewire\Beam\Workflows\Circuit\Data\WorkflowSubjectInputData;
use Splicewire\Beam\Workflows\Circuit\Data\WorkflowSubjectResultData;

/** Persist a subject transition using the host's trusted, durably prepared node visit. */
class WorkflowSubjectInvocable implements Invocable
{
    public const NAME = 'workflow.subject-transition';

    public function __construct(
        private WorkflowActionService $actions,
        private WorkflowCircuitExecutionProvider $executions,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    public function invoke(array $input): array
    {
        $execution = $this->executions->current();
        $request = WorkflowSubjectInputData::from($input);
        if ($request->subjectKind !== $execution->request->subjectKind
            || $request->subjectId !== $execution->request->subjectId
            || $request->transition !== $execution->request->transition) {
            throw new LogicException('The Circuit node configuration differs from its prepared request.');
        }

        $result = $this->actions->execute(
            $execution->identity,
            $execution->request,
            $execution->context,
            $execution->connection,
        );

        if (! $result->applied) {
            throw new WorkflowSubjectTransitionBlocked($result);
        }

        return ['type' => 'workflow.subject-transition', 'payload' => WorkflowSubjectResultData::fromResult($execution->request, $result)->toArray()];
    }

    /** @return array{type: string, schema: array<string, mixed>} */
    public static function inputPortSchema(): array
    {
        return ['type' => 'workflow.subject-request', 'schema' => app(Generator::class)->forRequest()->generate(new ReflectionClass(WorkflowSubjectInputData::class))];
    }

    /** @return array{type: string, schema: array<string, mixed>} */
    public static function outputPortSchema(): array
    {
        return ['type' => 'workflow.subject-transition', 'schema' => app(Generator::class)->forResponse()->generate(new ReflectionClass(WorkflowSubjectResultData::class))];
    }
}
