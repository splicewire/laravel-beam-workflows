<?php

namespace Splicewire\Beam\Workflows\Reactions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Registries\ActionHandlerRegistry;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Actions\WorkflowActionService;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionData;

/** Optional calendar integration: configuration is authorized at both ends before it is stored. */
class WorkflowReactionService
{
    public function __construct(private WorkflowActionService $workflows, private ActionHandlerRegistry $handlers) {}

    public function configure(WorkflowReactionData $data, WorkflowActionContext $context, ?ConnectionInterface $connection = null): string
    {
        if ($data->calendarDays < 0 || $data->calendarDays > 36500 || ! in_array($data->timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['calendar_days' => 'Choose a supported nonnegative calendar-day delay and IANA timezone.']);
        }
        $source = new WorkflowActionData($data->subjectKind, $data->subjectId, $data->transition);
        $subject = $this->workflows->authorize($source, $context, $connection);
        $connection = $subject->getConnection();

        return $connection->transaction(function () use ($data, $context, $source, $subject, $connection): string {
            $this->workflows->prepare($source, $context, $connection);
            $template = new CalendarActionData($data->actionKind, $data->actionPayload,
                CarbonImmutable::now('UTC')->toIso8601String(), $data->timezone, calendarId: $data->calendarId);
            $handler = $this->handlers->handler($data->actionKind)
                ?? throw ValidationException::withMessages(['action_kind' => 'The follow-up handler is unavailable.']);
            $calendarContext = new ActionContext($context->principal, $context->creator, $context->tenantToken);
            $handler->authorize($template, $calendarContext, $connection);
            $prepared = $handler->prepare($template, $calendarContext, $connection);
            $id = (string) Str::uuid();
            $connection->table('workflow_reaction_bindings')->insert([
                'id' => $id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->getKey(),
                'transition' => $data->transition, 'principal' => $context->principal, 'creator' => $context->creator,
                'tenant_token' => $context->tenantToken,
                'configuration' => json_encode(['authored' => $data->toArray(), 'template' => $prepared->toArray()], JSON_THROW_ON_ERROR),
                'created_at' => now('UTC')->format('Y-m-d H:i:s.uP'), 'updated_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
            ]);

            return $id;
        });
    }

    public function disable(string $id, int $expectedRevision, WorkflowActionContext $context, ?ConnectionInterface $connection = null): void
    {
        $connection ??= app('db')->connection();
        $connection->transaction(function () use ($id, $expectedRevision, $context, $connection): void {
            $binding = $connection->table('workflow_reaction_bindings')->where('id', $id)->where('tenant_token', $context->tenantToken)
                ->where('principal', $context->principal)->lockForUpdate()->first();
            if ($binding === null) {
                throw new AuthorizationException('The follow-up is unavailable.');
            }
            if ($binding->revision !== $expectedRevision) {
                throw new \Splicewire\Beam\Calendars\Actions\ActionConflict('The follow-up changed. Reload it.');
            }
            $authored = WorkflowReactionData::from(json_decode($binding->configuration, true, flags: JSON_THROW_ON_ERROR)['authored']);
            $this->workflows->authorize(new WorkflowActionData($authored->subjectKind, $authored->subjectId, $authored->transition), $context, $connection);
            $connection->table('workflow_reaction_bindings')->where('id', $id)->update([
                'enabled' => false, 'revision' => $expectedRevision + 1, 'updated_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
            ]);
        });
    }

    public function read(string $id, WorkflowActionContext $context, ?ConnectionInterface $connection = null): Data\WorkflowReactionRecordData
    {
        $connection ??= app('db')->connection();
        $binding = $connection->table('workflow_reaction_bindings')->where('id', $id)->where('tenant_token', $context->tenantToken)
            ->where('principal', $context->principal)->first();
        if ($binding === null) {
            throw new AuthorizationException('The follow-up is unavailable.');
        }
        $authored = WorkflowReactionData::from(json_decode($binding->configuration, true, flags: JSON_THROW_ON_ERROR)['authored']);
        $this->workflows->authorize(new WorkflowActionData($authored->subjectKind, $authored->subjectId, $authored->transition), $context, $connection);
        $deliveries = $connection->table('workflow_reaction_deliveries')->where('binding_id', $id)->orderBy('capture_sequence')->get();

        return new Data\WorkflowReactionRecordData($id, (int) $binding->revision, (bool) $binding->enabled, $authored,
            $deliveries->map(fn ($delivery) => new Data\WorkflowReactionDeliveryData(
                $delivery->id, $delivery->status, $delivery->transition_id, $delivery->action_id,
                CarbonImmutable::parse($delivery->anchored_at)->utc()->toIso8601String(), (int) $delivery->attempts,
                json_decode($delivery->blockers, true, flags: JSON_THROW_ON_ERROR),
            ))->all());
    }

    public function forSubject(string $kind, string $id, WorkflowActionContext $context, ?ConnectionInterface $connection = null): Data\WorkflowReactionListData
    {
        $subject = $this->workflows->authorize(new WorkflowActionData($kind, $id, ''), $context, $connection);
        $connection = $subject->getConnection();
        $ids = $connection->table('workflow_reaction_bindings')->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $id)->where('tenant_token', $context->tenantToken)->where('principal', $context->principal)->pluck('id');

        return new Data\WorkflowReactionListData($ids->map(fn ($id) => $this->read($id, $context, $connection))->all());
    }

    public function retry(string $id, int $expectedAttempts, WorkflowActionContext $context, ?ConnectionInterface $connection = null): Data\WorkflowReactionRecordData
    {
        $connection ??= app('db')->connection();
        $bindingId = $connection->transaction(function () use ($id, $expectedAttempts, $context, $connection): string {
            $delivery = $connection->table('workflow_reaction_deliveries')->where('id', $id)->where('tenant_token', $context->tenantToken)
                ->where('principal', $context->principal)->lockForUpdate()->first();
            if ($delivery === null) {
                throw new AuthorizationException('The follow-up delivery is unavailable.');
            }
            $this->read($delivery->binding_id, $context, $connection);
            if ($delivery->attempts !== $expectedAttempts || ! in_array($delivery->status, ['failed', 'blocked', 'pending'], true)) {
                throw new \Splicewire\Beam\Calendars\Actions\ActionConflict('The follow-up delivery changed. Reload it.');
            }
            $path = json_decode($delivery->causal_path, true, flags: JSON_THROW_ON_ERROR);
            if (count(array_keys($path, $delivery->binding_id, true)) > 1 || count($path) > 16) {
                throw ValidationException::withMessages(['id' => 'A causal cycle cannot be retried. Change the follow-up configuration.']);
            }
            $connection->table('workflow_reaction_deliveries')->where('id', $id)->update(['status' => 'pending', 'blockers' => '[]']);

            return $delivery->binding_id;
        });

        return $this->read($bindingId, $context, $connection);
    }
}
