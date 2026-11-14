<?php

namespace Splicewire\Beam\Workflows\Reactions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Calendars\Actions\ActionConflict;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Workflows\Localization\WorkflowError;
use Splicewire\Beam\Workflows\Localization\WorkflowErrorData;

/** Optional calendars integration only: the core provider and responder never reference this class. */
class WorkflowActionConflict extends ActionConflict
{
    private ?WorkflowError $error = null;

    /** @param array<string, scalar> $params */
    public static function for(WorkflowError $error, array $params = []): self
    {
        $replace = [];
        foreach ($params as $key => $value) {
            $replace[':'.$key] = (string) $value;
        }
        $exception = new self(strtr($error->defaultMessage(), $replace));
        $exception->error = $error;

        return $exception;
    }

    public function explicitCodedError(): ?CodedError
    {
        return $this->error;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || $this->error === null) {
            return null;
        }

        return new JsonResponse((new WorkflowErrorData($this->error->message(), $this->error->code()))->toArray(),
            $this->getStatusCode(), $this->getHeaders(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
