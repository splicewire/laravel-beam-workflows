<?php

namespace Splicewire\Beam\Workflows\Control;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A committed control fact, independent of the lossy activity-log projection. */
class WorkflowTransitionFact extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'from' => 'array',
            'to' => 'array',
            'causal_path' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Committed workflow facts are immutable.'));
        static::deleting(fn () => throw new LogicException('Committed workflow facts are immutable.'));
    }
}
