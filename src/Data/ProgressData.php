<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * Determinate progress on a status event — the wire twin of {@see \Splicewire\Beam\Workflows\Display\Progress}.
 *
 * Present only when the producer knows the total (ADR-0098 Display shape); absent = indeterminate
 * (a spinner, not a bar). The value object stays the emit-side type; this is what a projection puts
 * on the wire, which is why it carries `#[TypeScript]` and the value object does not.
 */
#[TypeScript]
class ProgressData extends BeamData
{
    public function __construct(
        public int $done,
        public int $total,
    ) {}
}
