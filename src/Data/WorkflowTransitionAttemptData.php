<?php

namespace Splicewire\Beam\Workflows\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Workflows\Control\TransitionResult;

/**
 * The `output:` shape a generic `#[ParticleOp(kind: OperationKind::Write)]` transition action returns —
 * the outcome (`applied`/`blockers`, from {@see TransitionResult}) PLUS the refreshed
 * {@see WorkflowProjectionData} so a stepper UI can update its button set in one round trip, whether
 * the attempt succeeded or not. `LifecycleService::transition()` never throws on an illegal/guarded/
 * unmanaged transition — it returns an un-applied result carrying the reason — so the HTTP shape
 * mirrors that: a caller branches on `applied`, never on a caught exception. `projection` is null only
 * when the model is genuinely unmanaged (no binding for its type); an applied-but-still-managed model
 * always carries one.
 */
#[TypeScript]
class WorkflowTransitionAttemptData extends BeamData
{
    public function __construct(
        public bool $applied,
        /** @var string[] */
        public array $blockers,
        public ?WorkflowProjectionData $projection,
    ) {}

    public static function fromResult(TransitionResult $result, ?array $projection): self
    {
        return new self(
            applied: $result->applied,
            blockers: $result->blockers,
            projection: $projection === null ? null : WorkflowProjectionData::from($projection),
        );
    }
}
