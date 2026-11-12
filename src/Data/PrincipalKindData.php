<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Data\RoleOptionData;
use Splicewire\Beam\Data\BeamData;

/**
 * A host-supplied recipient kind. Only the role kind carries options today, using Accounts' role
 * vocabulary. A future kind with non-role options needs its own declared option type.
 */
#[TypeScript]
class PrincipalKindData extends BeamData
{
    public function __construct(
        public string $kind,
        public string $label,
        /** @var array<int, RoleOptionData>|null */
        public ?array $options = null,
    ) {}
}
