<?php

namespace Splicewire\Beam\Workflows\Calendar;

use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Contracts\ActionHandler;
use Splicewire\Beam\Calendars\Data\ActionResultData;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Splicewire\Beam\Calendars\Models\CalendarActionAttempt;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Actions\WorkflowActionService;

/** Optional adapter: calendar timing delegates subject semantics to workflows. */
class WorkflowActionHandler implements ActionHandler
{
    public const KIND = 'kind.workflow-transition';

    public function __construct(protected WorkflowActionService $workflows) {}

    public function authorize(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): void
    {
        $this->workflows->authorize(WorkflowActionData::from($data->payload), $this->context($context), $connection);
    }

    public function prepare(CalendarActionData $data, ActionContext $context, ConnectionInterface $connection): CalendarActionData
    {
        $data->payload = $this->workflows->prepare(
            WorkflowActionData::from($data->payload), $this->context($context), $connection,
        )->toArray();

        return $data;
    }

    public function execute(CalendarAction $action, CalendarActionAttempt $attempt, ConnectionInterface $connection): ActionResultData
    {
        $context = new WorkflowActionContext(
            $action->principal, $action->creator, $action->tenant_token,
            runId: $action->correlation_id,
            causationId: $action->payload['_workflow_causation']['transition_id'] ?? $action->origin,
            causalPath: $action->payload['_workflow_causation']['path'] ?? [],
        );
        $result = $this->workflows->execute('calendar:'.$attempt->id,
            WorkflowActionData::from($action->payload), $context, $connection);

        return new ActionResultData($result->applied ? 'applied' : 'blocked', $result->blockers, $result->toArray());
    }

    private function context(ActionContext $context): WorkflowActionContext
    {
        return new WorkflowActionContext($context->principal, $context->creator, $context->tenantToken);
    }
}
