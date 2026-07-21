<?php

namespace Splicewire\Beam\Workflows\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A throwaway subject standing in for "the process/model whose status is being reported" — a
 * Composition, a FragmentUrlBatch, a CircuitRun in real use. Carries a `payload` column so a test
 * can assert the invariant that emitting status NEVER writes status into the artifact's own data.
 */
class FakeProcess extends Model
{
    protected $table = 'fake_processes';

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];
}
