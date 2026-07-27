<?php

namespace Splicewire\Beam\Workflows\Tests\Fixtures;

use Spatie\Activitylog\Models\Activity;

/**
 * A second Activity model bound to a different table, standing in for a host's connection-pinned
 * audit model (e.g. the app's central-connection `CentralActivityLog`). Used to prove the
 * per-subject activity-model resolution seam routes a mapped subject's status into a different
 * store than the default `activity_log`.
 */
class AltActivity extends Activity
{
    protected $table = 'alt_activity_log';
}
