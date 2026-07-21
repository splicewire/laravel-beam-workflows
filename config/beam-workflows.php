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
