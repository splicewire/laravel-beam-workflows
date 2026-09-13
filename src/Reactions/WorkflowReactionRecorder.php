<?php

namespace Splicewire\Beam\Workflows\Reactions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Splicewire\Beam\Workflows\Control\WorkflowTransitionFact;

/** Required deliveries are recorded inside the subject transaction, before any best-effort event. */
class WorkflowReactionRecorder
{
    public function __construct(private Contracts\WorkflowSubjectSnapshot $snapshots) {}

    public function capture(Model $subject, WorkflowTransitionFact $fact): void
    {
        $connection = $subject->getConnection();
        $bindings = $connection->table('workflow_reaction_bindings')
            ->where('subject_type', $fact->subject_type)->where('subject_id', $fact->subject_id)
            ->where('transition', $fact->transition)->where('enabled', true)->orderBy('id')->lockForUpdate()->get();
        foreach ($bindings as $binding) {
            $sequence = $binding->capture_sequence + 1;
            $connection->table('workflow_reaction_bindings')->where('id', $binding->id)->update(['capture_sequence' => $sequence]);
            $path = $fact->causal_path ?? [];
            $cycle = in_array($binding->id, $path, true) || count($path) >= 16;
            $connection->table('workflow_reaction_deliveries')->insert([
                'id' => (string) Str::uuid(), 'binding_id' => $binding->id, 'transition_id' => $fact->id,
                'status' => $cycle ? 'blocked' : 'pending', 'attempts' => 0, 'capture_sequence' => $sequence,
                'request' => $binding->configuration,
                'source_snapshot' => json_encode($this->snapshots->capture($subject), JSON_THROW_ON_ERROR),
                'principal' => $binding->principal, 'creator' => $binding->creator, 'tenant_token' => $binding->tenant_token,
                'causal_path' => json_encode([...$path, $binding->id], JSON_THROW_ON_ERROR),
                'anchored_at' => $fact->occurred_at->format('Y-m-d H:i:s.uP'),
                'blockers' => json_encode($cycle ? ['The workflow follow-up would re-enter its causal chain.'] : [], JSON_THROW_ON_ERROR),
                'created_at' => now('UTC')->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }
}
