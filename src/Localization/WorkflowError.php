<?php

namespace Splicewire\Beam\Workflows\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

enum WorkflowError: string implements CodedError
{
    use IsCodedError;

    #[Message('Choose a supported nonnegative calendar-day delay and IANA timezone.')]
    case InvalidDelay = 'workflow.invalid_delay';

    #[Message('The follow-up handler is unavailable.')]
    case HandlerUnavailable = 'workflow.handler_unavailable';

    #[Message('The follow-up is unavailable.')]
    case FollowUpUnavailable = 'workflow.follow_up_unavailable';

    #[Message('The follow-up changed. Reload it.')]
    case FollowUpChanged = 'workflow.follow_up_changed';

    #[Message('The follow-up delivery is unavailable.')]
    case DeliveryUnavailable = 'workflow.delivery_unavailable';

    #[Message('The follow-up delivery changed. Reload it.')]
    case DeliveryChanged = 'workflow.delivery_changed';

    #[Message('A causal cycle cannot be retried. Change the follow-up configuration.')]
    case CausalCycle = 'workflow.causal_cycle';

    #[Message('Choose a history page size between 1 and 100.')]
    case HistoryPageSize = 'workflow.history_page_size';
}
