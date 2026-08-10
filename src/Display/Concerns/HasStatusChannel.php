<?php

namespace Splicewire\Beam\Workflows\Display\Concerns;

use Splicewire\Beam\Workflows\Display\Events\StatusEmitted;

/**
 * Exposes a model's own Seam A broadcast channel as a `status_channel` attribute, so a Data DTO
 * can carry it to the frontend and a consumer subscribes to the server-resolved name instead of
 * reconstructing {@see StatusEmitted::channelNameFor()}'s dotted-FQCN convention client-side —
 * that reconstruction drifts silently the moment the model's class relocates to a different
 * namespace/package (the class itself moving is exactly the kind of refactor this estate does
 * routinely).
 */
trait HasStatusChannel
{
    public function initializeHasStatusChannel(): void
    {
        $this->appends[] = 'status_channel';
    }

    public function getStatusChannelAttribute(): string
    {
        return StatusEmitted::channelNameFor($this);
    }
}
