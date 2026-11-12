<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Declarative guard or effect metadata; implementation and selection stay with the host. */
#[TypeScript]
class GuardCatalogEntryData extends BeamData
{
    public function __construct(
        public string $name,
        public string $label,
        /** @var array<string, mixed> */
        public array $paramsSchema,
    ) {}
}
