<?php

namespace Splicewire\Beam\Workflows\Localization;

use Illuminate\Auth\Access\AuthorizationException;
use Splicewire\Beam\Localization\CodedError;

class WorkflowAuthorizationException extends AuthorizationException
{
    private ?WorkflowError $error = null;

    /** @param array<string, scalar> $params */
    public static function for(WorkflowError $error, array $params = []): self
    {
        $replace = [];
        foreach ($params as $key => $value) {
            $replace[':'.$key] = (string) $value;
        }
        // Reaction delivery diagnostics may store getMessage(); it must remain raw English.
        $exception = new self(strtr($error->defaultMessage(), $replace));
        $exception->error = $error;

        return $exception;
    }

    public function explicitCodedError(): ?CodedError
    {
        return $this->error;
    }
}
