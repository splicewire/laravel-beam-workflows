<?php

namespace Splicewire\Beam\Workflows\Reactions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionLocalTime;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Calendars\Data\CalendarActionData;
use Splicewire\Beam\Calendars\Models\CalendarAction;
use Throwable;

/** Durable fact delivery. A sweep can recover a process exit after publication committed. */
class WorkflowReactionDispatcher
{
    use \Illuminate\Database\DetectsConcurrencyErrors;

    public function __construct(private ActionService $actions) {}

    public function deliver(string $id, string $tenantToken, ?ConnectionInterface $connection = null): ?string
    {
        $connection ??= app('db')->connection();

        return $connection->transaction(function () use ($id, $tenantToken, $connection): ?string {
            // Serialize a binding's deliveries so a newer publication can supersede its old expiry.
            $candidate = $connection->table('workflow_reaction_deliveries')->where('id', $id)->where('tenant_token', $tenantToken)->first();
            if ($candidate === null) {
                return null;
            }
            $connection->table('workflow_reaction_bindings')->where('id', $candidate->binding_id)->lockForUpdate()->first();
            $delivery = $connection->table('workflow_reaction_deliveries')->where('id', $id)->lockForUpdate()->first();
            if ($delivery->status !== 'pending') {
                return $delivery->action_id;
            }
            try {
                return $connection->transaction(function () use ($delivery, $connection): string {
                    $configuration = json_decode($delivery->request, true, flags: JSON_THROW_ON_ERROR);
                    $template = CalendarActionData::from($configuration['template']);
                    if ($configuration['authored']['calendar_days'] > 0 && $connection->table('workflow_reaction_deliveries')
                        ->where('binding_id', $delivery->binding_id)->where('capture_sequence', '>', $delivery->capture_sequence)->exists()) {
                        $connection->table('workflow_reaction_deliveries')->where('id', $delivery->id)->update(['status' => 'superseded']);

                        return '';
                    }
                    $days = $configuration['authored']['calendar_days'];
                    $template->dueAt = ActionLocalTime::shiftDays(CarbonImmutable::parse($delivery->anchored_at), $days, $template->timezone)->format('Y-m-d\TH:i:s.uP');
                    $template->origin = 'transition:'.$delivery->transition_id.':binding:'.$delivery->binding_id;
                    $template->correlationId = $delivery->transition_id;
                    $template->payload['_workflow_causation'] = [
                        'transition_id' => $delivery->transition_id,
                        'path' => json_decode($delivery->causal_path, true, flags: JSON_THROW_ON_ERROR),
                        'source_snapshot' => json_decode($delivery->source_snapshot, true, flags: JSON_THROW_ON_ERROR),
                    ];
                    $context = new ActionContext($delivery->principal, $delivery->creator, $delivery->tenant_token);
                    $action = $this->actions->schedulePrepared($template, $context, $connection);
                    if ($days > 0) {
                        $older = $connection->table('workflow_reaction_deliveries')->where('binding_id', $delivery->binding_id)
                            ->whereNotNull('action_id')->where('capture_sequence', '<', $delivery->capture_sequence)->get();
                        foreach ($older as $prior) {
                            $pending = CalendarAction::on($connection->getName())->whereKey($prior->action_id)->lockForUpdate()->first();
                            // A manual reschedule advances revision and detaches replacement policy.
                            if ($pending !== null && $pending->status === 'pending' && $pending->revision === 1) {
                                $this->actions->cancel($pending->id, 1, $context, $connection);
                                $connection->table('workflow_reaction_deliveries')->where('id', $prior->id)->update(['status' => 'superseded']);
                            }
                        }
                    }
                    $connection->table('workflow_reaction_deliveries')->where('id', $delivery->id)->update([
                        'status' => 'scheduled', 'action_id' => $action->id, 'attempts' => $delivery->attempts + 1,
                        'completed_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
                    ]);

                    return $action->id;
                });
            } catch (Throwable $exception) {
                // A database deadlock aborts the transaction, not the required obligation.
                // Let the outer transaction retry the whole binding/action lock sequence.
                if ($this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }
                if (! $exception instanceof AuthorizationException) {
                    report($exception);
                }
                $connection->table('workflow_reaction_deliveries')->where('id', $delivery->id)->update([
                    'status' => $exception instanceof AuthorizationException ? 'blocked' : 'failed',
                    'attempts' => $delivery->attempts + 1,
                    'blockers' => json_encode([$exception instanceof AuthorizationException ? $exception->getMessage() : 'The follow-up could not be scheduled.'], JSON_THROW_ON_ERROR),
                ]);

                return null;
            }
        }, attempts: 3);
    }

    public function sweep(string $tenantToken, ?ConnectionInterface $connection = null): array
    {
        $connection ??= app('db')->connection();
        $ids = $connection->table('workflow_reaction_deliveries')->where('tenant_token', $tenantToken)->where('status', 'pending')->orderBy('anchored_at')->pluck('id');

        return $ids->map(fn ($id) => $this->deliver($id, $tenantToken, $connection))->filter()->values()->all();
    }
}
