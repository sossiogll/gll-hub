<?php

declare(strict_types=1);

namespace App\Http\Api;

use RuntimeException;

/** A definitive refusal of the API, with its stable code. */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', 404, $message);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_request', 422, $message);
    }
}
