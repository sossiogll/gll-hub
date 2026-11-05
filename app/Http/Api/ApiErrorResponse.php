<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * What any failure looks like from outside: a stable code and a message. Anything not foreseen is "unavailable" with
 * a message that says nothing of the inside; the exception itself is still reported.
 */
final class ApiErrorResponse
{
    public static function of(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiError => self::json($e->errorCode, $e->getMessage(), $e->status),
            $e instanceof ThrottleRequestsException => self::json('rate_limited', 'Too many requests.', 429, $e->getHeaders()),
            $e instanceof NotFoundHttpException => self::json('not_found', 'There is nothing here.', 404),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => self::json('invalid_request', 'This request is not part of the API.', $e->getStatusCode()),
            default => self::json('unavailable', 'The service is unavailable; try again.', 503),
        };
    }

    /** @param array<string, mixed> $headers */
    private static function json(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message], $status, $headers);
    }
}
