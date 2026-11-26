<?php

namespace Splicewire\Beam\Workflows\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The `beam.workflows` Config Section (ADR-0245, ticket 13). Every key is Host-tier with a Deploy
 * effect: a host sets it at deploy time and no lower tier may override it until the key is opted in.
 */
#[ConfigRoot(
    root: 'beam.workflows',
    title: 'Beam workflows',
    description: 'The Beam workflow engine\'s status-projection and control seams: the activitylog channel emitted StatusEvents land on, the run-grouping and actor keys they carry, the per-subject Activity model routing that keeps a central subject\'s status readable outside a tenant boundary, whether an emit also broadcasts, and the Circuit node capability the state machine registers under.',
)]
class BeamWorkflowsSection extends ConfigSection
{
    public function __construct(
        #[Title('API route prefix'), Description('The URL path prefix the workflows package mounts its HTTP API under (default beam/workflows). Set it per host to relocate the endpoints; it is the base every workflows route hangs off.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy, env: 'BEAM_WORKFLOWS_API_ROOT')]
        public string $api_root,
        #[Title('Status log channel'), Description('The spatie/laravel-activitylog log_name that emitted StatusEvents project into — a stable channel the Display timeline queries by name. Change it to keep workflow status separate from other activity logs.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public string $status_log_name,
        #[Title('Activity model map'), Description('Maps a workflow subject class to the Activity model (hence table and connection) its status is written to, matched by instanceof so a base class or contract key works. Route a central subject\'s status to a central Activity model when the default would otherwise land on a per-tenant connection — e.g. tenant provisioning, whose first events fire before the tenant schema exists. A class-string to class-string map with no closures, so it stays config-cache safe; an empty map falls through to the global Default activity model override below, and only then to spatie\'s own configured model.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        #[MapValues('string')]
        public array $activity_models,
        #[Title('Default activity model'), Description('A single global override for the Activity model used when no per-subject activity model map entry matches. Null leaves spatie\'s own configured model in place.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public mixed $activity_model,
        #[Title('Run-grouping key'), Description('The properties key a shared run identifier rides on every status entry from one run, so a UI can render that run\'s timeline coherently. activitylog v5 dropped the native batch_uuid column, so the run id lives at properties.<run_id_key>, indexable via a JSON path.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public string $run_id_key,
        #[Title('Actor key'), Description('The activity-entry properties key the opaque host-supplied actor token (a kind:selector string such as user:42) is STORED under on a transition\'s status entry — the single canonical who for a stored workflow-status entry. The engine suppresses spatie\'s automatic Auth::user() causer for these entries, so this stored property is the only record of who drove the move. A null token omits the stored property; the broadcast payload is separate and always carries the actor under its own fixed actor field (null when there is none), marking a system, queue or migration path with no actor.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public string $actor_key,
        #[Title('Broadcast on emit'), Description('Whether an emitted StatusEvent reaches the configured broadcaster. The StatusEmitted event is ALWAYS dispatched, so in-process listeners and tests observe every emit; this flag only gates the websocket/SSE transport, letting a UI subscribe instead of polling. Off by default so the package stays inert on the wire until a host opts in and configures a broadcaster.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public bool $broadcast,
        #[Title('Node capability name'), Description('The capability name the workflow state-machine node registers under in the Circuit kernel\'s CapabilityManifest (Seam B). Consulted only when splicewire/laravel-circuit-engine is installed.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public string $node_capability,
    ) {
    }
}
