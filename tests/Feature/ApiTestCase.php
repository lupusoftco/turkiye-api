<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Feature;

use PHPUnit\Framework\TestCase;
use TurkiyeApi\App;
use TurkiyeApi\Config;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;
use TurkiyeApi\Modules\Exchange\TcmbClient;
use TurkiyeApi\Modules\LinkPreview\SafeHttpClient;

abstract class ApiTestCase extends TestCase
{
    protected App $app;
    protected FakeTcmbClient $tcmb;
    protected FakeHttpClient $http;

    /** @param array<string, string> $config */
    protected function makeApp(array $config = []): App
    {
        $this->tcmb = new FakeTcmbClient();
        $this->http = new FakeHttpClient('test');
        return $this->app = new App(new Config($config + [
            'DB_PATH' => ':memory:',
            'APP_URL' => 'https://api.test',
            'RATE_LIMIT_ANON' => '1000',
        ]), $this->tcmb, $this->http);
    }

    protected function setUp(): void
    {
        $this->makeApp();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->app->handle(new Request('GET', $path, $query, array_change_key_case($headers)));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function post(string $path, array $body, array $headers = []): Response
    {
        return $this->app->handle(new Request('POST', $path, [], array_change_key_case($headers) + ['content-type' => 'application/json'], (string) json_encode($body)));
    }

    /** @return array<string, mixed> */
    protected static function json(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'Response is not JSON: ' . substr($response->body, 0, 200));
        return $decoded;
    }

    protected static function assertProblem(Response $response, int $status): void
    {
        self::assertSame($status, $response->status, $response->body);
        self::assertStringStartsWith('application/problem+json', $response->headers['Content-Type']);
        $body = self::json($response);
        self::assertSame($status, $body['status']);
        self::assertNotEmpty($body['detail']);
    }
}

/**
 * Serves the fixture bulletin for any requested weekday, except dates in $missing.
 */
final class FakeTcmbClient extends TcmbClient
{
    /** @var list<string> */
    public array $requested = [];

    /** @var list<string> */
    public array $missing = ['2026-07-15'];

    public function fetch(?\DateTimeImmutable $date): ?array
    {
        $date ??= new \DateTimeImmutable('today', new \DateTimeZone('Europe/Istanbul'));
        $this->requested[] = $date->format('Y-m-d');
        if (in_array($date->format('Y-m-d'), $this->missing, true)) {
            return null;
        }
        $bulletin = self::parse((string) file_get_contents(__DIR__ . '/../fixtures/tcmb-2026-10-22.xml'));
        $bulletin['date'] = $date->format('Y-m-d');
        return $bulletin;
    }
}

final class FakeHttpClient extends SafeHttpClient
{
    /** @var array<string, array{status: int, content_type: string, location: string, body: string}> */
    public array $responses = [];

    public int $requests = 0;

    protected function resolve(string $host): array
    {
        return $host === 'internal.example' ? ['10.0.0.8'] : ['93.184.216.34'];
    }

    protected function request(string $url, string $host, int $port, string $ip): array
    {
        $this->requests++;
        return $this->responses[$url] ?? ['status' => 404, 'content_type' => 'text/html', 'location' => '', 'body' => ''];
    }
}
