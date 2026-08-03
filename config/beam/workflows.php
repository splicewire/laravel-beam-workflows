<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display substrate — status projection channel
    |--------------------------------------------------------------------------
    |
    | Emitted StatusEvents project into spatie/laravel-activitylog under this
    | log_name (a stable channel). The Display timeline is queried by this name.
    |
    */
    'status_log_name' => 'status',

    /*
    |--------------------------------------------------------------------------
    | Activity model resolution — tenancy / connection awareness
    |--------------------------------------------------------------------------
    |
    | spatie/laravel-activitylog resolves the Activity model (hence table +
    | connection) from `activitylog.activity_model` — the DEFAULT one lands on
    | the framework's default connection, which a multi-tenancy layer swaps
    | per-tenant. That is correct for an in-tenant subject, but WRONG for a
    | central subject whose status must be readable centrally (e.g. tenant
    | provisioning: the operator reads it outside any tenant boundary, and the
    | first events fire before the tenant schema even exists).
    |
    | `activity_models` maps a subject class to the Activity model its status
    | should be written to (matched by `instanceof`, so a base class or contract
    | key works). `activity_model` is a global default override. Both null/empty =
    | spatie's own configured model (no change). A class-string map — no closures —
    | so it stays config-cache safe.
    |
    |   'activity_models' => [
    |       App\Models\Tenant::class => App\Models\CentralActivityLog::class,
    |   ],
    |
    */
    'activity_models' => [],

    'activity_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Run-grouping key
    |--------------------------------------------------------------------------
    |
    | All StatusEvents from one run share a run identifier so a UI can render one
    | run's timeline coherently. activitylog v5 dropped the native `batch_uuid`
    | column, so the run id rides `properties.<run_id_key>` instead — indexable
    | via a JSON path, portable across the v5 schema.
    |
    */
    'run_id_key' => 'run_id',

    /*
    |--------------------------------------------------------------------------
    | Actor key — the opaque "who" on a transition's status entry
    |--------------------------------------------------------------------------
    |
    | When a transition carries a host-supplied actor token (a `kind:selector`
    | string like `user:42`), it rides `properties.<actor_key>` on the status
    | activity AND the broadcast payload — the SINGLE canonical "who" for a
    | workflow-status entry. The engine suppresses spatie's auto-`Auth::user()`
    | causer for these entries (it is opaque to identity), so the token in this
    | property is the only representation of who drove the move. Null = a system
    | / queue / migration path with no actor.
    |
    */
    'actor_key' => 'actor',

    /*
    |--------------------------------------------------------------------------
    | Broadcast on emit
    |--------------------------------------------------------------------------
    |
    | When true, emitting a StatusEvent fires a broadcastable event so a UI can
    | subscribe over websockets/SSE instead of polling. Off by default so the
    | package is inert until a host opts in (and configures a broadcaster).
    |
    */
    'broadcast' => false,

    /*
    |--------------------------------------------------------------------------
    | Control seam — state-machine node capability
    |--------------------------------------------------------------------------
    |
    | The capability name the state-machine node registers under in the Circuit
    | kernel's CapabilityManifest (Seam B). Only used when
    | splicewire/laravel-circuit-engine is installed.
    |
    */
    'node_capability' => 'workflow.apply',

];
