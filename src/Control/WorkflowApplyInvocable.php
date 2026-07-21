<?php

namespace Splicewire\Beam\Workflows\Control;

use InvalidArgumentException;
use Rushing\Popcorn\Binding;
use Rushing\Popcorn\Contracts\Invocable;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;

/**
 * The state-machine Circuit node type (Seam B): a `local` popcorn {@see Invocable} that slots into
 * a Circuit graph with ZERO new engine architecture. It wraps a symfony/workflow definition and
 * uses it purely as a transition/guard **calculator** over the marking carried in its input port —
 * persistence stays the host's job (the marking rides the envelope; on resume the host re-hydrates
 * it). This is exactly why the node survives the kernel's durable suspend/resume for free: it holds
 * nothing across a pause.
 *
 * Contract (array-in / array-out, per {@see Invocable}):
 *   input  (merged node config + `_circuit`): `definition` (a registered name or an inline
 *          blueprint array) + `{ marking, event }` — marking/event flow in from an upstream node's
 *          output envelope (`_circuit.inputs`) when present, else from static config.
 *   output envelope `workflow.marking`: `{ marking, transition, applied, blockers }`.
 *
 * A guarded/illegal transition returns `applied: false` with the guard reason in `blockers` —
 * rejected WITHOUT applying — so the host marks the node-run failed and surfaces why, rather than
 * a half-mutated marking.
 */
class WorkflowApplyInvocable implements Invocable
{
    public function __construct(
        protected string $capabilityName,
        protected WorkflowRunner $runner,
        protected WorkflowRegistry $registry,
    ) {}

    public function name(): string
    {
        return $this->capabilityName;
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function invoke(array $input): array
    {
        $blueprint = $this->resolveBlueprint($input);
        $upstream = $this->upstreamPayload($input);

        $event = $upstream['event'] ?? $input['event'] ?? null;
        if (! is_string($event) || $event === '') {
            throw new InvalidArgumentException('The workflow node requires an `event` (transition name).');
        }

        $marking = $upstream['marking'] ?? $input['marking'] ?? $blueprint->initialMarking;
        $context = $upstream['context'] ?? $input['context'] ?? [];
        $runId = $input['run_id'] ?? ($input['_circuit']['context']['run_id'] ?? null);

        $subject = MarkingSubject::fromPlaces(
            array_values(array_map('strval', (array) $marking)),
            is_array($context) ? $context : [],
        );

        $result = $this->runner->apply($blueprint, $subject, $event, statusSubject: null, runId: $runId);

        return ['type' => 'workflow.marking', 'payload' => $result->toArray()];
    }

    /**
     * The canonical typed ports for the node (Data → JSON Schema, ADR-0034 Decision 3). A consumer
     * declares these on its Node so the kernel validates the envelope on the boundary. Marking is a
     * *list* of places — never a scalar — so workflow-net markings pass through intact.
     *
     * @return array{type: string, schema: array<string, mixed>}
     */
    public static function inputPortSchema(): array
    {
        return [
            'type' => 'workflow.marking',
            'schema' => [
                'type' => 'object',
                'required' => ['event'],
                'properties' => [
                    'marking' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'event' => ['type' => 'string'],
                    'context' => ['type' => 'object'],
                ],
            ],
        ];
    }

    /**
     * @return array{type: string, schema: array<string, mixed>}
     */
    public static function outputPortSchema(): array
    {
        return [
            'type' => 'workflow.marking',
            'schema' => [
                'type' => 'object',
                'required' => ['marking', 'transition', 'applied'],
                'properties' => [
                    'marking' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'transition' => ['type' => 'string'],
                    'applied' => ['type' => 'boolean'],
                    'blockers' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ];
    }

    protected function resolveBlueprint(array $input): WorkflowBlueprint
    {
        $definition = $input['definition'] ?? null;

        if (is_array($definition)) {
            return WorkflowBlueprint::fromArray($definition);
        }

        if (is_string($definition)) {
            return $this->registry->get($definition);
        }

        throw new InvalidArgumentException('The workflow node requires a `definition` (a registered name or an inline blueprint array).');
    }

    /**
     * The payload of the first upstream envelope reaching this node — how `{ marking, event }`
     * flows in from a preceding node. Empty when the node runs standalone off its own config.
     *
     * @return array<string, mixed>
     */
    protected function upstreamPayload(array $input): array
    {
        foreach ($input['_circuit']['inputs'] ?? [] as $envelope) {
            if (isset($envelope['payload']) && is_array($envelope['payload'])) {
                return $envelope['payload'];
            }
        }

        return [];
    }
}
