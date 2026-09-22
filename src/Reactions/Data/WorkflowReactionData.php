<?php

namespace Splicewire\Beam\Workflows\Reactions\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Data\BeamData;

/** One explicit, per-subject transition follow-up; no general-purpose rules language. */
class WorkflowReactionData extends BeamData
{
    /** @param array<string, mixed> $actionPayload */
    public function __construct(
        #[MapName('subject_kind')] #[Description('Registered workflow subject kind whose transition triggers the follow-up.')]
        public string $subjectKind,
        #[MapName('subject_id')] #[Description('Record identifier of the workflow subject.')]
        public string $subjectId,
        #[Description('Transition name in the subject workflow that triggers this follow-up.')]
        public string $transition,
        #[MapName('action_kind')] #[Description('Registered calendar action handler to run after the transition.')]
        public string $actionKind,
        #[MapName('action_payload')] #[Description('Input prepared and authorized by the selected calendar action handler.')]
        public array $actionPayload,
        #[MapName('calendar_days')] #[Description('Number of local calendar days after the transition before the follow-up becomes due.')]
        public int $calendarDays,
        #[Description('IANA timezone used to calculate the local calendar-day delay.')]
        public string $timezone,
        #[MapName('calendar_id')] #[Description('Calendar associated with the scheduled follow-up action, when supplied.')]
        public ?string $calendarId = null,
    ) {}

    public static function rules(): array
    {
        return ['calendar_days' => ['integer', 'min:0', 'max:36500'], 'timezone' => ['timezone']];
    }
}
