<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every request with a correlation id.
 *
 * The id flows into the audit log, the application log and the response header,
 * so a support ticket quoting one header value can be traced through every
 * record the request touched.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header(self::HEADER);

        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $requestId)) {
            $requestId = (string) Str::ulid();
        }

        app(AuditLogger::class)->setRequestId($requestId);

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
