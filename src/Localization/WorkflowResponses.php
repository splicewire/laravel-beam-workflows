<?php

namespace Splicewire\Beam\Workflows\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Only this package's authorization identity, including Laravel's prepared previous exception. */
final class WorkflowResponses
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }
        $source = $exception instanceof WorkflowAuthorizationException ? $exception : $exception->getPrevious();
        if (! $source instanceof WorkflowAuthorizationException || $source->explicitCodedError() === null) {
            return null;
        }
        $error = $source->explicitCodedError();

        return new JsonResponse((new WorkflowErrorData($error->message(), $error->code()))->toArray(),
            $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 403,
            $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
