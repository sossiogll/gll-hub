<?php

declare(strict_types=1);

namespace Gll\Hub\Contracts\Tests;

use Gll\Hub\Contracts\Exceptions\Unavailable;
use Gll\Hub\Contracts\Http\HttpResponse;
use Gll\Hub\Contracts\Testing\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FakeHttpClientTest extends TestCase
{
    private const URL = 'https://service.test/one-url';

    public function test_a_closure_answers_from_the_request_body(): void
    {
        $http = (new FakeHttpClient())->respond(self::URL, static fn (?string $body): HttpResponse => new HttpResponse(200, "got {$body}"));

        $this->assertSame('got a', $http->request('POST', self::URL, [], 'a')->body);
        $this->assertSame('got b', $http->request('POST', self::URL, [], 'b')->body);
    }

    public function test_a_closure_may_answer_with_a_failure(): void
    {
        $http = (new FakeHttpClient())->respond(self::URL, static fn (): Unavailable => new Unavailable('down'));

        $this->expectException(Unavailable::class);
        $http->get(self::URL);
    }

    public function test_an_unknown_url_still_fails_the_test(): void
    {
        $this->expectException(RuntimeException::class);
        (new FakeHttpClient())->get(self::URL);
    }
}
