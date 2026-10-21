<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Feature;

use TurkiyeApi\Http\Request;
use TurkiyeApi\Modules\Address\AddressImporter;
use TurkiyeApi\Storage\ApiKeys;

final class ApiTest extends ApiTestCase
{
    public function testIndexAndHealth(): void
    {
        $index = self::json($this->get('/'));
        self::assertSame('Türkiye API', $index['data']['name']);
        self::assertSame('https://api.test/docs/', $index['data']['docs']);
        self::assertSame('ok', self::json($this->get('/health'))['data']['status']);
    }

    public function testOpenApiDocumentsInBothLanguages(): void
    {
        $en = $this->get('/openapi.yaml');
        $tr = $this->get('/openapi.tr.yaml');
        self::assertSame(200, $tr->status);
        self::assertStringContainsString('summary: Bir tarihin tüm kurları', $tr->body);
        self::assertStringContainsString('summary: All rates for a date', $en->body);
        // Same paths and operations in both documents.
        preg_match_all('/operationId: (\w+)/', $en->body, $a);
        preg_match_all('/operationId: (\w+)/', $tr->body, $b);
        self::assertSame($a[1], $b[1]);
    }

    public function testUnknownPathAndWrongMethod(): void
    {
        self::assertProblem($this->get('/v1/nope'), 404);
        $r = $this->get('/v1/validate/tckn');
        self::assertProblem($r, 405);
        self::assertStringContainsString('POST', $r->headers['Allow']);
    }

    public function testCorsPreflightAndHeaders(): void
    {
        $r = $this->app->handle(new Request('OPTIONS', '/v1/holidays', [], ['origin' => 'https://lupusoft.com']));
        self::assertSame(204, $r->status);
        self::assertSame('*', $r->headers['Access-Control-Allow-Origin']);
        self::assertStringContainsString('X-API-Key', $r->headers['Access-Control-Allow-Headers']);

        $this->makeApp(['CORS_ORIGINS' => 'https://lupusoft.com']);
        self::assertSame('https://lupusoft.com', $this->get('/health', [], ['Origin' => 'https://lupusoft.com'])->headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $this->get('/health', [], ['Origin' => 'https://evil.test'])->headers);
    }

    public function testRateLimiting(): void
    {
        $this->makeApp(['RATE_LIMIT_ANON' => '2']);
        self::assertSame('1', $this->get('/v1/holidays')->headers['X-RateLimit-Remaining']);
        self::assertSame(200, $this->get('/v1/holidays')->status);
        $limited = $this->get('/v1/holidays');
        self::assertProblem($limited, 429);
        self::assertArrayHasKey('Retry-After', $limited->headers);
        self::assertSame(200, $this->get('/health')->status, 'meta endpoints are not limited');
    }

    public function testApiKeys(): void
    {
        $this->makeApp(['RATE_LIMIT_ANON' => '1', 'RATE_LIMIT_KEY' => '5']);
        $key = (new ApiKeys($this->app->db()))->create('Test')['key'];

        self::assertProblem($this->get('/v1/holidays', [], ['X-API-Key' => 'tka_wrong']), 401);
        $r = $this->get('/v1/holidays', [], ['X-API-Key' => $key]);
        self::assertSame(200, $r->status);
        self::assertSame('5', $r->headers['X-RateLimit-Limit']);
        self::assertSame(200, $this->get('/v1/holidays', [], ['Authorization' => "Bearer $key"])->status);

        $this->makeApp(['REQUIRE_API_KEY' => 'true']);
        self::assertProblem($this->get('/v1/holidays'), 401);
    }

    public function testUsageIsRecordedWithoutIps(): void
    {
        $this->get('/v1/holidays');
        $this->get('/v1/qr', ['data' => 'x']);
        $rows = $this->app->db()->all('SELECT consumer, endpoint, hits FROM usage_daily ORDER BY endpoint');
        self::assertSame(['holidays', 'qr'], array_column($rows, 'endpoint'));
        self::assertStringNotContainsString('127.0.0.1', $rows[0]['consumer']);
    }

    // --- Holidays -------------------------------------------------------

    public function testHolidays(): void
    {
        $body = self::json($this->get('/v1/holidays', ['year' => '2026', 'type' => 'religious']));
        self::assertSame(9, $body['meta']['count']);
        self::assertSame('2026-03-19', $body['data'][0]['date']);
        self::assertTrue($body['data'][0]['half_day']);
        self::assertSame('13:00', $body['data'][0]['starts_at']);

        $full = self::json($this->get('/v1/holidays', ['year' => '2026', 'half_days' => 'false']));
        self::assertSame(14, $full['meta']['count']);

        self::assertProblem($this->get('/v1/holidays', ['year' => '1990']), 400);
        self::assertProblem($this->get('/v1/holidays', ['year' => 'abc']), 400);
    }

    public function testHolidayCheckAndBusinessDays(): void
    {
        $check = self::json($this->get('/v1/holidays/check', ['date' => '2026-10-29']))['data'];
        self::assertTrue($check['is_holiday']);
        self::assertFalse($check['is_business_day']);

        $count = self::json($this->get('/v1/business-days/count', ['from' => '2026-10-26', 'to' => '2026-10-30']))['data'];
        self::assertSame(5, $count['calendar_days']);
        self::assertSame(4, $count['business_days']);
        $countOff = self::json($this->get('/v1/business-days/count', ['from' => '2026-10-26', 'to' => '2026-10-30', 'half_day' => 'off']))['data'];
        self::assertSame(3, $countOff['business_days']);

        $add = self::json($this->get('/v1/business-days/add', ['date' => '2026-10-28', 'days' => '1']))['data'];
        self::assertSame('2026-10-30', $add['result']);
        self::assertSame('Cuma', $add['result_weekday']);

        self::assertProblem($this->get('/v1/business-days/add', ['date' => '2026-02-30', 'days' => '1']), 400);
        self::assertProblem($this->get('/v1/business-days/count', ['from' => '2000-01-01', 'to' => '2020-01-01']), 400);
    }

    // --- Validation -----------------------------------------------------

    public function testValidation(): void
    {
        $tckn = self::json($this->post('/v1/validate/tckn', ['value' => '10000000146']));
        self::assertTrue($tckn['data']['valid']);
        self::assertArrayHasKey('note', $tckn['meta']);

        $iban = self::json($this->post('/v1/validate/iban', ['value' => 'TR33 0006 1005 1978 6457 8413 26']))['data'];
        self::assertSame('TR33 0006 1005 1978 6457 8413 26', $iban['formatted']);

        $plate = self::json($this->post('/v1/validate/plate', ['value' => '34abc123']))['data'];
        self::assertSame('İstanbul', $plate['city']);

        $batch = self::json($this->post('/v1/validate/phone', ['values' => ['05321234567', '123', '+90 212 555 12 34']]));
        self::assertSame(3, $batch['meta']['count']);
        self::assertSame(2, $batch['meta']['valid_count']);
        self::assertSame('123', $batch['data'][1]['input']);

        self::assertProblem($this->post('/v1/validate/tckn', []), 400);
        self::assertProblem($this->post('/v1/validate/passport', ['value' => 'x']), 404);
        self::assertProblem($this->post('/v1/validate/vkn', ['values' => array_fill(0, 101, '1')]), 400);
        self::assertProblem($this->app->handle(new Request('POST', '/v1/validate/vkn', [], [], 'not json')), 400);
    }

    // --- Text -----------------------------------------------------------

    public function testTextEndpoints(): void
    {
        $words = self::json($this->get('/v1/text/number-to-words', ['number' => '1.250,50', 'currency' => 'try']))['data'];
        self::assertSame('bin iki yüz elli Türk lirası elli kuruş', $words['words']);
        self::assertSame('#BinİkiYüzElliTürkLirasıElliKuruş#', $words['invoice']);
        self::assertSame('BİN İKİ YÜZ ELLİ TÜRK LİRASI ELLİ KURUŞ', $words['upper']);

        self::assertSame('İSTANBUL', self::json($this->get('/v1/text/case', ['text' => 'istanbul', 'to' => 'upper']))['data']['result']);
        self::assertSame('cagri-sogut', self::json($this->post('/v1/text/slug', ['text' => 'Çağrı Söğüt']))['data']['slug']);
        self::assertSame('İs-tan-bul', self::json($this->get('/v1/text/syllables', ['text' => 'İstanbul']))['data']['hyphenated']);

        self::assertProblem($this->get('/v1/text/number-to-words', ['number' => '12abc']), 400);
        self::assertProblem($this->get('/v1/text/number-to-words', ['number' => '12', 'currency' => 'XYZ']), 400);
        self::assertProblem($this->get('/v1/text/case', ['text' => 'a', 'to' => 'weird']), 400);
        self::assertProblem($this->get('/v1/text/slug', ['text' => str_repeat('a', 10001)]), 400);
    }

    // --- QR -------------------------------------------------------------

    public function testQrSvgAndPng(): void
    {
        $svg = $this->get('/v1/qr', ['data' => 'https://lupusoft.com', 'size' => '256', 'color' => '1a2b3c']);
        self::assertSame(200, $svg->status);
        self::assertStringStartsWith('image/svg+xml', $svg->headers['Content-Type']);
        self::assertStringContainsString('width="256"', $svg->body);
        self::assertStringContainsString('#1a2b3c', $svg->body);

        $png = $this->get('/v1/qr', ['data' => 'Merhaba Dünya', 'format' => 'png', 'size' => '200']);
        self::assertSame('image/png', $png->headers['Content-Type']);
        self::assertStringStartsWith("\x89PNG", $png->body);
        $info = getimagesizefromstring($png->body);
        self::assertNotFalse($info);
        self::assertEqualsWithDelta(200, $info[0], 30);

        self::assertProblem($this->get('/v1/qr', []), 400);
        self::assertProblem($this->get('/v1/qr', ['data' => 'x', 'color' => 'red']), 400);
        self::assertProblem($this->get('/v1/qr', ['data' => 'x', 'size' => '5000']), 400);
    }

    // --- Exchange -------------------------------------------------------

    public function testExchangeRatesForPublishedDay(): void
    {
        $body = self::json($this->get('/v1/exchange/rates', ['date' => '2026-10-22', 'currencies' => 'usd,EUR']));
        self::assertSame('2026-10-22', $body['meta']['bulletin_date']);
        self::assertSame(['EUR', 'USD'], array_column($body['data'], 'code'));
        self::assertSame(41.8512, $body['data'][1]['forex_buying']);

        // Second request is served from the database.
        $this->get('/v1/exchange/rates', ['date' => '2026-10-22']);
        self::assertSame(['2026-10-22'], $this->tcmb->requested);
    }

    public function testWeekendsAndHolidaysFallBackToPreviousBulletin(): void
    {
        $sunday = self::json($this->get('/v1/exchange/rates', ['date' => '2026-10-25']));
        self::assertSame('2026-10-23', $sunday['meta']['bulletin_date']);
        self::assertSame(['2026-10-23'], $this->tcmb->requested, 'no request is made for weekend days');

        // 15 July 2026 (Wednesday) is a public holiday: TCMB publishes no bulletin.
        $holiday = self::json($this->get('/v1/exchange/rates/USD', ['date' => '2026-07-15']));
        self::assertSame('2026-07-14', $holiday['meta']['bulletin_date']);
        self::assertSame('USD', $holiday['data']['code']);

        $before = count($this->tcmb->requested);
        $this->get('/v1/exchange/rates/USD', ['date' => '2026-07-15']);
        self::assertSame($before, count($this->tcmb->requested), 'missing days are remembered');
    }

    public function testConvert(): void
    {
        $r = self::json($this->get('/v1/exchange/convert', ['from' => 'USD', 'to' => 'EUR', 'amount' => '100', 'date' => '2026-10-22']))['data'];
        self::assertEqualsWithDelta(100 * 41.8512 / 48.7020, $r['result'], 0.0001);
        self::assertSame('forex_buying', $r['rate_type']);

        $jpy = self::json($this->get('/v1/exchange/convert', ['from' => 'JPY', 'to' => 'TRY', 'amount' => '1000', 'date' => '2026-10-22']))['data'];
        self::assertEqualsWithDelta(276.145, $jpy['result'], 0.0001);

        self::assertProblem($this->get('/v1/exchange/convert', ['from' => 'XDR', 'to' => 'TRY', 'type' => 'forex_selling', 'date' => '2026-10-22']), 422);
        self::assertProblem($this->get('/v1/exchange/convert', ['from' => 'ABC', 'to' => 'TRY', 'date' => '2026-10-22']), 422);
        self::assertProblem($this->get('/v1/exchange/convert', ['from' => 'USD', 'to' => 'TRY', 'amount' => '-5']), 400);
        self::assertProblem($this->get('/v1/exchange/rates', ['currencies' => 'USD,CHF', 'date' => '2026-10-22']), 400);
    }

    public function testHistory(): void
    {
        $body = self::json($this->get('/v1/exchange/rates/USD/history', ['from' => '2026-07-13', 'to' => '2026-07-24', 'type' => 'forex_selling']));
        $dates = array_column($body['data'], 'date');
        // Ten weekdays minus the 15 July holiday; weekends are never requested.
        self::assertSame(['2026-07-13', '2026-07-14', '2026-07-16', '2026-07-17', '2026-07-20', '2026-07-21', '2026-07-22', '2026-07-23', '2026-07-24'], $dates);
        self::assertNotContains('2026-07-18', $this->tcmb->requested);
        self::assertSame(['date', 'unit', 'forex_selling'], array_keys($body['data'][0]));
        self::assertProblem($this->get('/v1/exchange/rates/USD/history', ['from' => '2024-01-01', 'to' => '2026-01-01']), 400);
    }

    public function testTcmbOutageIsReportedAsBadGateway(): void
    {
        $this->tcmb->missing = [];
        $failing = new class () extends \TurkiyeApi\Modules\Exchange\TcmbClient {
            public function fetch(?\DateTimeImmutable $date): ?array
            {
                throw new \RuntimeException('connection refused');
            }
        };
        $this->app = new \TurkiyeApi\App(new \TurkiyeApi\Config(['DB_PATH' => ':memory:']), $failing);
        self::assertProblem($this->get('/v1/exchange/rates', ['date' => '2026-10-22']), 502);
    }

    // --- Address --------------------------------------------------------

    public function testAddressEndpoints(): void
    {
        // With AUTO_SETUP disabled the data must be imported explicitly.
        $this->makeApp(['AUTO_SETUP' => 'false']);
        self::assertProblem($this->get('/v1/address/cities'), 503);
        (new AddressImporter($this->app->db(), dirname(__DIR__, 2) . '/data/address'))->import();

        $cities = self::json($this->get('/v1/address/cities'));
        self::assertCount(81, $cities['data']);

        $istanbul = self::json($this->get('/v1/address/cities/istanbul'))['data'];
        self::assertSame('34', $istanbul['code']);
        self::assertCount(39, $istanbul['districts']);
        self::assertSame($istanbul, self::json($this->get('/v1/address/cities/34'))['data']);

        $sisli = self::json($this->get('/v1/address/cities/34/districts/sisli/neighbourhoods'));
        self::assertSame('Şişli', $sisli['meta']['district']['name']);
        self::assertContains('Mecidiyeköy Mah', array_column($sisli['data'], 'name'));

        $districtId = $sisli['meta']['district']['id'];
        self::assertSame($sisli['data'], self::json($this->get("/v1/address/districts/$districtId/neighbourhoods"))['data']);

        $search = self::json($this->get('/v1/address/search', ['q' => 'mecidiyekoy', 'city' => 'İstanbul']))['data'];
        self::assertSame('Mecidiyeköy Mah', $search[0]['name']);
        self::assertSame('Şişli', $search[0]['district']['name']);

        $postal = self::json($this->get('/v1/address/postal-codes/' . $search[0]['postal_code']))['data'];
        self::assertContains('Mecidiyeköy Mah', array_column($postal, 'name'));

        $distance = self::json($this->get('/v1/address/distance', ['from' => 'istanbul', 'to' => '06']))['data'];
        self::assertGreaterThan(400, $distance['km']);
        self::assertSame('Ankara', $distance['to']['name']);

        self::assertProblem($this->get('/v1/address/cities/99'), 404);
        self::assertProblem($this->get('/v1/address/search', ['q' => 'a']), 400);
        self::assertProblem($this->get('/v1/address/postal-codes/00000'), 404);
    }

    public function testAddressDataIsImportedOnFirstUse(): void
    {
        $body = self::json($this->get('/v1/address/cities/6'));
        self::assertSame('Ankara', $body['data']['name']);
    }

    public function testSubdirectoryInstallation(): void
    {
        $this->makeApp(['APP_URL' => '']);
        $request = new Request('GET', '/', [], ['host' => 'lupusoft.com', 'x-forwarded-proto' => 'https'], '', '127.0.0.1', '/api');
        $index = self::json($this->app->handle($request));
        self::assertSame('https://lupusoft.com/api/docs/', $index['data']['docs']);

        $yaml = $this->app->handle(new Request('GET', '/openapi.yaml', [], ['host' => 'lupusoft.com', 'x-forwarded-proto' => 'https'], '', '127.0.0.1', '/api'))->body;
        self::assertStringContainsString("servers:\n  - url: https://lupusoft.com/api\n", $yaml);
    }

    // --- Link preview ---------------------------------------------------

    public function testLinkPreview(): void
    {
        $this->http->responses['https://example.com/old'] = ['status' => 301, 'content_type' => '', 'location' => '/blog/kahve', 'body' => ''];
        $this->http->responses['https://example.com/blog/kahve'] = [
            'status' => 200,
            'content_type' => 'text/html; charset=utf-8',
            'location' => '',
            'body' => (string) file_get_contents(dirname(__DIR__) . '/fixtures/page.html'),
        ];

        $body = self::json($this->get('/v1/link-preview', ['url' => 'example.com/old']));
        self::assertSame('https://example.com/old', $body['data']['requested_url']);
        self::assertSame('https://example.com/blog/kahve', $body['data']['url']);
        self::assertSame("Kahve & Kod: İstanbul'da bir gün", $body['data']['title']);
        self::assertFalse($body['meta']['cached']);

        $again = self::json($this->get('/v1/link-preview', ['url' => 'https://example.com/old']));
        self::assertTrue($again['meta']['cached']);
        self::assertSame(2, $this->http->requests);

        self::assertProblem($this->get('/v1/link-preview', ['url' => 'http://internal.example/']), 422);
        self::assertProblem($this->get('/v1/link-preview', ['url' => 'http://192.168.1.1/']), 422);
        self::assertProblem($this->get('/v1/link-preview', ['url' => 'https://example.com/missing']), 422);
    }

    public function testLinkPreviewHasItsOwnSmallerLimit(): void
    {
        $this->makeApp(['RATE_LIMIT_LINK_PREVIEW' => '1']);
        $this->get('/v1/link-preview', ['url' => 'https://example.com/a']);
        self::assertProblem($this->get('/v1/link-preview', ['url' => 'https://example.com/b']), 429);
        self::assertSame(200, $this->get('/v1/holidays')->status);
    }

    public function testLinkPreviewCanBeDisabled(): void
    {
        $this->makeApp(['LINK_PREVIEW_ENABLED' => 'false']);
        self::assertProblem($this->get('/v1/link-preview', ['url' => 'https://example.com']), 404);
    }
}
