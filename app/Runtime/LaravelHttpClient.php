<?php

declare(strict_types=1);

namespace App\Runtime;

use Gll\Hub\Contracts\Exceptions\InvalidCredentials;
use Gll\Hub\Contracts\Exceptions\RateLimited;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpClient;
use Gll\Hub\Contracts\Http\HttpResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The contract's HttpClient on top of Laravel's: the one place that owns timeouts,
 * the user agent and the meaning of a failed request. Packages read the answer;
 * they never classify a failure themselves.
 */
final class LaravelHttpClient implements HttpClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $pending = $this->http
            ->withUserAgent((string) config('gllhub.http.user_agent'))
            ->timeout((int) config('gllhub.http.timeout'))
            ->connectTimeout((int) config('gllhub.http.connect_timeout'))
            ->withHeaders($headers);

        if ($body !== null) {
            $pending = $pending->withBody($body, $this->contentType($headers));
        }

        try {
            $response = $pending->send(strtoupper($method), $url);
        } catch (HttpClientException $e) {
            // Connection errors and timeouts alike. The exception's own message repeats the full URL,
            // query string included, so it stays out of what we log and show; it travels as `previous`.
            $this->logger->warning('Request failed', ['url' => $this->where($url), 'error' => $e::class]);

            throw new Unavailable("{$this->where($url)} could not be reached", 0, $e);
        }

        $status = $response->status();
        $at = $this->where($url);

        if ($this->isRateLimited($response)) {
            $this->logger->warning('Rate limited', ['url' => $at, 'status' => $status]);

            throw new RateLimited($this->retryAfter($response), "{$at} asked to slow down");
        }
        if ($status === 401 || $status === 403) {
            throw new InvalidCredentials("{$at} refused the credentials ({$status})");
        }
        if ($status >= 500) {
            $this->logger->warning('Server error', ['url' => $at, 'status' => $status]);

            throw new Unavailable("{$at} answered {$status}");
        }

        $lower = [];
        foreach ($response->headers() as $name => $values) {
            $lower[strtolower((string) $name)] = implode(', ', $values);
        }

        return new HttpResponse($status, $response->body(), $lower);
    }

    /**
     * A 429 always is. A 403 is when it says so: some services refuse with 403 once a quota is spent,
     * and that is a wait, not refused credentials. It says so with Retry-After, or with an empty
     * X-RateLimit-Remaining and the X-RateLimit-Reset it will refill at.
     */
    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        return $response->status() === 403
            && ($response->header('Retry-After') !== ''
                || ($response->header('X-RateLimit-Remaining') === '0' && ctype_digit($response->header('X-RateLimit-Reset'))));
    }

    /**
     * Seconds to wait: Retry-After (seconds or an HTTP date), else the moment X-RateLimit-Reset names
     * (epoch seconds). Anything else, or a moment already past, falls back to the default.
     */
    private function retryAfter(Response $response): int
    {
        $after = trim($response->header('Retry-After'));
        $reset = trim($response->header('X-RateLimit-Reset'));

        if (ctype_digit($after)) {
            return max(1, (int) $after);
        }

        $at = match (true) {
            $after !== '' => strtotime($after),
            ctype_digit($reset) => (int) $reset,
            default => false,
        };
        $wait = $at === false ? 0 : $at - Carbon::now()->getTimestamp();

        return $wait > 0 ? $wait : (int) config('gllhub.http.default_retry_after');
    }

    /** @param array<string, string> $headers */
    private function contentType(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                return $value;
            }
        }

        return 'application/octet-stream';
    }

    /** The URL without its query string, which may carry a token: safe to log and to show. */
    private function where(string $url): string
    {
        return strtok($url, '?#') ?: $url;
    }
}
