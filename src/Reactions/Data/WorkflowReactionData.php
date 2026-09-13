<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/** One explicit, per-subject transition follow-up; no general-purpose rules language. */
class WorkflowReactionData extends BeamData
{
    /** @param array<string, mixed> $actionPayload */
    public function __construct(
        #[MapName('subject_kind')] public string $subjectKind,
        #[MapName('subject_id')] public string $subjectId,
        public string $transition,
        #[MapName('action_kind')] public string $actionKind,
        #[MapName('action_payload')] public array $actionPayload,
        #[MapName('calendar_days')] public int $calendarDays,
        public string $timezone,
        #[MapName('calendar_id')] public ?string $calendarId = null,
    ) {}

    public static function rules(): array
    {
        return ['calendar_days' => ['integer', 'min:0', 'max:36500'], 'timezone' => ['timezone']];
    }
}
