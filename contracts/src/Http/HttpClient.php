<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Http;

/**
 * The HTTP client a package uses to reach its outside service, given by the core
 * through the Context. The core owns timeouts, the user agent, logging and the
 * classification of failures, in ONE place:
 *
 * - network error, timeout, 5xx → throws Exceptions\Unavailable
 * - 429, or 403 that carries Retry-After (or X-RateLimit-Remaining: 0 with X-RateLimit-Reset)
 *                                → throws Exceptions\RateLimited (the wait is honoured)
 * - 401, any other 403           → throws Exceptions\InvalidCredentials
 * - anything else (2xx, 3xx after redirects, 404, other 4xx) → returned; the package decides
 */
interface HttpClient
{
    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): HttpResponse;

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
