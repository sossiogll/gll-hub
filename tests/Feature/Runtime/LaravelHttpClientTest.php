<?php

declare(strict_types=1);

namespace Tests\Feature\Runtime;

use App\Runtime\LaravelHttpClient;
use Gll\Hub\Contracts\Exceptions\InvalidCredentials;
use Gll\Hub\Contracts\Exceptions\RateLimited;
use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LaravelHttpClientTest extends TestCase
{
    private function client(): HttpClient
    {
        return $this->app->make(HttpClient::class);
    }

    public function test_the_core_gives_packages_its_laravel_client(): void
    {
        $this->assertInstanceOf(LaravelHttpClient::class, $this->client());
    }

    public function test_a_success_is_returned_with_lowercase_headers(): void
    {
        Http::fake(['api.test/*' => Http::response('{"ok":true}', 200, ['Content-Type' => 'application/json', 'X-Rate-Remaining' => '41'])]);

        $response = $this->client()->get('https://api.test/things');

        $this->assertSame(200, $response->status);
        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('41', $response->header('X-Rate-Remaining'));
    }

    public function test_a_status_the_package_may_want_to_read_is_returned_not_thrown(): void
    {
        Http::fake(['*' => Http::response('nothing here', 404)]);

        $response = $this->client()->get('https://api.test/missing');

        $this->assertSame(404, $response->status);
        $this->assertFalse($response->ok());
        $this->assertSame('nothing here', $response->body);
    }

    public function test_server_errors_are_unavailable(): void
    {
        Http::fake(['*' => Http::sequence()->pushStatus(500)->pushStatus(502)->pushStatus(503)]);

        foreach ([500, 502, 503] as $status) {
            try {
                $this->client()->get('https://api.test/x?token=secret');
                $this->fail("{$status} should be Unavailable");
            } catch (Unavailable $e) {
                $this->assertStringContainsString((string) $status, $e->getMessage());
                $this->assertStringNotContainsString('secret', $e->getMessage(), 'the query string may hold a token');
            }
        }
    }

    public function test_a_connection_error_or_timeout_is_unavailable_and_does_not_leak_the_url_query(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://api.test/x?token=secret'));

        try {
            $this->client()->get('https://api.test/x?token=secret');
            $this->fail('expected Unavailable');
        } catch (Unavailable $e) {
            $this->assertStringContainsString('https://api.test/x could not be reached', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    public function test_401_and_403_are_invalid_credentials(): void
    {
        Http::fake(['*' => Http::sequence()->pushStatus(401)->pushStatus(403)]);

        foreach ([401, 403] as $status) {
            try {
                $this->client()->get('https://api.test/private');
                $this->fail("{$status} should be InvalidCredentials");
            } catch (InvalidCredentials $e) {
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function test_429_is_rate_limited_for_as_long_as_retry_after_says(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '120'])]);

        try {
            $this->client()->get('https://api.test/x');
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            $this->assertSame(120, $e->retryAfter);
        }
    }

    public function test_retry_after_may_be_a_date_and_falls_back_to_the_default(): void
    {
        $this->travelTo('2026-05-05 10:00:00');
        $headers = ['Tue, 05 May 2026 10:01:30 GMT', null, 'soon', 'Tue, 05 May 2026 09:00:00 GMT', '0'];
        $sequence = Http::sequence();
        foreach ($headers as $header) {
            $sequence->push('', 429, $header === null ? [] : ['Retry-After' => $header]);
        }
        Http::fake(['*' => $sequence]);

        $waits = [];
        foreach ($headers as $ignored) {
            try {
                $this->client()->get('https://api.test/x');
                $this->fail('expected RateLimited');
            } catch (RateLimited $e) {
                $waits[] = $e->retryAfter;
            }
        }

        // a date ahead; no header; neither a number nor a date; a date in the past (all fall back to 60); zero is never 0
        $this->assertSame([90, 60, 60, 60, 1], $waits);
    }

    public function test_a_403_with_retry_after_is_rate_limited_not_refused_credentials(): void
    {
        Http::fake(['*' => Http::response('', 403, ['Retry-After' => '45'])]);

        try {
            $this->client()->get('https://api.test/x');
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            $this->assertSame(45, $e->retryAfter);
        }
    }

    public function test_a_403_with_an_empty_quota_waits_until_it_resets(): void
    {
        $this->travelTo('2026-05-05 10:00:00');
        Http::fake(['*' => Http::response('', 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) now()->addMinutes(10)->getTimestamp(),
        ])]);

        try {
            $this->client()->get('https://api.test/x');
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            $this->assertSame(600, $e->retryAfter);
        }
    }

    public function test_a_403_with_quota_left_or_no_signal_stays_refused_credentials(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 403, ['X-RateLimit-Remaining' => '12', 'X-RateLimit-Reset' => '9999999999'])
            ->push('', 403, ['X-RateLimit-Remaining' => '0'])]);

        foreach ([1, 2] as $ignored) {
            try {
                $this->client()->get('https://api.test/x');
                $this->fail('expected InvalidCredentials');
            } catch (InvalidCredentials $e) {
                $this->assertStringContainsString('403', $e->getMessage());
            }
        }
    }

    public function test_the_user_agent_and_the_request_are_sent_as_configured(): void
    {
        config(['gllhub.http.user_agent' => 'gllHub-test/1.0']);
        Http::fake(['*' => Http::response('ok')]);

        $this->client()->request('post', 'https://api.test/hook', ['X-Thing' => 'a', 'Content-Type' => 'text/plain'], 'hello');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.test/hook'
            && $request->body() === 'hello'
            && $request->header('User-Agent') === ['gllHub-test/1.0']
            && $request->header('X-Thing') === ['a']
            && $request->header('Content-Type') === ['text/plain']);
    }

    public function test_a_get_sends_the_given_headers_and_no_body(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $this->client()->get('https://api.test/x', ['Authorization' => 'Bearer abc']);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->header('Authorization') === ['Bearer abc']
            && $request->body() === '');
    }
}
