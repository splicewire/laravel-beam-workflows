<?php

namespace Splicewire\Beam\Workflows\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority;
use Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\TransitionResult;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/** Durable, model-blind subject actions used by calendar and Circuit adapters. */
class WorkflowActionService
{
    public function __construct(
        private WorkflowActuator $actuator,
        private LifecycleService $lifecycle,
        private WorkflowActionAuthority $authority,
        private DefinitionStore $definitions,
    ) {}

    public function authorize(WorkflowActionData $data, WorkflowActionContext $context, ?ConnectionInterface $connection = null): Model
    {
        $subject = $this->actuator->subject($data->subjectKind, $data->subjectId)
            ?? throw new InvalidArgumentException('The workflow subject no longer resolves.');
        if ($connection !== null && $subject->getConnection() !== $connection) {
            throw new AuthorizationException('The workflow subject is outside the execution connection.');
        }
        $this->authority->authorize($subject, $data->transition, $context);

        return $subject;
    }

    public function prepare(WorkflowActionData $data, WorkflowActionContext $context, ?ConnectionInterface $connection = null): WorkflowActionData
    {
        $subject = $this->authorize($data, $context, $connection);

        return $subject->getConnection()->transaction(function () use ($subject, $data, $context) {
            $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $this->authority->authorize($locked, $data->transition, $context);
            $version = $this->lifecycle->pinDefinition($locked)
                ?? throw new InvalidArgumentException('The subject has no pinnable workflow definition.');
            $blueprint = $this->definitions->onConnection($locked->getConnectionName())->version($version)?->toBlueprint();
            $names = array_map(fn ($transition) => $transition->name, $blueprint?->transitions ?? []);
            if (! in_array($data->transition, $names, true)) {
                throw new InvalidArgumentException('The transition is not declared by the subject workflow.');
            }

            // Ignore a client-supplied pin and capture the effective server-owned version.
            return new WorkflowActionData($data->subjectKind, $data->subjectId, $data->transition, $version);
        });
    }

    public function execute(string $identity, WorkflowActionData $data, WorkflowActionContext $context, ?ConnectionInterface $connection = null): TransitionResult
    {
        if ($identity === '' || $data->definitionVersion === null) {
            throw new InvalidArgumentException('A durable identity and prepared definition pin are required.');
        }
        $connection ??= app('db')->connection();
        $request = [$data->subjectKind, $data->subjectId, $data->transition, $data->definitionVersion,
            $context->principal, $context->creator, $context->tenantToken, $context->runId,
            $context->causationId, $context->causalPath];
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

        return $connection->transaction(function () use ($identity, $data, $context, $connection, $request, $hash) {
            $connection->table('workflow_action_receipts')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'identity' => $identity, 'tenant_token' => $context->tenantToken,
                'request_hash' => $hash, 'principal' => $context->principal, 'creator' => $context->creator,
                'request' => json_encode($request, JSON_THROW_ON_ERROR), 'result' => null,
                'created_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
            ]);
            $receipt = $connection->table('workflow_action_receipts')
                ->where('tenant_token', $context->tenantToken)->where('identity', $identity)->lockForUpdate()->first();
            if ($receipt === null || ! hash_equals($receipt->request_hash, $hash)) {
                throw new LogicException('The workflow action identity belongs to a different request.');
            }
            if ($receipt->result !== null) {
                $result = json_decode($receipt->result, true, flags: JSON_THROW_ON_ERROR);

                return new TransitionResult($result['marking'], $result['transition'], $result['applied'], $result['blockers'], $result['transition_id']);
            }

            try {
                $subject = $this->authorize($data, $context, $connection);
                $subject = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                $this->authority->authorize($subject, $data->transition, $context);
                if (! $subject instanceof WorkflowManaged || $subject->{$subject->workflowVersionAttribute()} !== $data->definitionVersion) {
                    $result = new TransitionResult([], $data->transition, false, ['The subject workflow definition changed; create a new schedule using the current definition.']);
                } else {
                    $result = $this->actuator->transition($subject, $data->transition,
                        new TransitionContext($context->principal, $context->runId, $context->causationId, $context->causalPath));
                }
            } catch (AuthorizationException|InvalidArgumentException|ModelNotFoundException $e) {
                $result = new TransitionResult([], $data->transition, false, [$e->getMessage()]);
            }
            $connection->table('workflow_action_receipts')->where('id', $receipt->id)
                ->update(['result' => json_encode($result->toArray(), JSON_THROW_ON_ERROR)]);

            return $result;
        });
    }
}
