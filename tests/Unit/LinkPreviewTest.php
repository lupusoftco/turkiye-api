<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Modules\LinkPreview\MetadataExtractor;
use TurkiyeApi\Modules\LinkPreview\SafeHttpClient;
use TurkiyeApi\Modules\LinkPreview\UrlResolver;

final class LinkPreviewTest extends TestCase
{
    /** @return iterable<array{string, string, string}> */
    public static function urls(): iterable
    {
        yield ['https://a.com/b/c/d', 'g', 'https://a.com/b/c/g'];
        yield ['https://a.com/b/c/d', './g', 'https://a.com/b/c/g'];
        yield ['https://a.com/b/c/d', '../g', 'https://a.com/b/g'];
        yield ['https://a.com/b/c/d', '../../../g', 'https://a.com/g'];
        yield ['https://a.com/b/c/d', '/g', 'https://a.com/g'];
        yield ['https://a.com/b/c/d', '//cdn.com/x.png', 'https://cdn.com/x.png'];
        yield ['https://a.com/b/c/d', 'http://other.com/', 'http://other.com/'];
        yield ['https://a.com:8443/b/', 'x?y=1', 'https://a.com:8443/b/x?y=1'];
        yield ['https://a.com/b/c?q=1', '?q=2', 'https://a.com/b/c?q=2'];
        yield ['https://a.com', 'favicon.ico', 'https://a.com/favicon.ico'];
    }

    /** @dataProvider urls */
    public function testUrlResolver(string $base, string $ref, string $expected): void
    {
        self::assertSame($expected, UrlResolver::resolve($base, $ref));
    }

    /** @return iterable<array{string, bool}> */
    public static function ips(): iterable
    {
        yield ['8.8.8.8', true];
        yield ['1.1.1.1', true];
        yield ['2606:4700:4700::1111', true];
        yield ['127.0.0.1', false];
        yield ['10.1.2.3', false];
        yield ['172.16.5.4', false];
        yield ['192.168.1.1', false];
        yield ['169.254.169.254', false]; // cloud metadata
        yield ['100.64.0.1', false];      // CGNAT
        yield ['0.0.0.0', false];
        yield ['::1', false];
        yield ['fd00::1', false];
        yield ['fe80::1', false];
        yield ['::ffff:127.0.0.1', false];
        yield ['::ffff:8.8.8.8', true];
        yield ['not-an-ip', false];
    }

    /** @dataProvider ips */
    public function testPublicIpCheck(string $ip, bool $public): void
    {
        self::assertSame($public, SafeHttpClient::isPublicIp($ip));
    }

    /** @return iterable<array{string}> */
    public static function blockedUrls(): iterable
    {
        yield ['http://127.0.0.1/'];
        yield ['http://[::1]/'];
        yield ['http://localhost/admin'];
        yield ['http://169.254.169.254/latest/meta-data/'];
        yield ['ftp://example.com/'];
        yield ['https://user:pass@example.com/'];
        yield ['https://example.com:22/'];
        yield ['file:///etc/passwd'];
    }

    /** @dataProvider blockedUrls */
    public function testUnsafeUrlsAreRejected(string $url): void
    {
        $this->expectException(ApiException::class);
        (new SafeHttpClient('test'))->check($url);
    }

    public function testHostResolvingToPrivateAddressIsRejected(): void
    {
        $client = new class ('test') extends SafeHttpClient {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34', '10.0.0.5']; // one public, one private
            }
        };
        $this->expectException(ApiException::class);
        $client->check('https://rebind.example/');
    }

    public function testMetadataExtraction(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../fixtures/page.html');
        $m = MetadataExtractor::extract($html, 'https://example.com/blog/kahve', 'text/html; charset=utf-8');

        self::assertSame("Kahve & Kod: İstanbul'da bir gün", $m['title']);
        self::assertSame("Şişli'de yeni açılan kahveci.", $m['description']);
        self::assertSame('Örnek Blog', $m['site_name']);
        self::assertSame('https://example.com/blog/images/kapak.jpg', $m['image']); // resolved against <base>
        self::assertSame('https://example.com/favicon-32.png', $m['favicon']);
        self::assertSame('article', $m['type']);
        self::assertSame('tr_TR', $m['locale']);
        self::assertSame('#123456', $m['theme_color']);
        self::assertSame('https://example.com/blog/kahve-ve-kod', $m['canonical']);
    }

    public function testMetadataFallbacksAndLegacyEncoding(): void
    {
        $html = mb_convert_encoding('<html><head><title>Çiçek Pasajı</title><meta name="description" content="Beyoğlu"></head></html>', 'Windows-1254', 'UTF-8');
        $m = MetadataExtractor::extract($html, 'https://site.com.tr/a', 'text/html; charset=windows-1254');
        self::assertSame('Çiçek Pasajı', $m['title']);
        self::assertSame('Beyoğlu', $m['description']);
        self::assertNull($m['image']);
        self::assertSame('https://site.com.tr/favicon.ico', $m['favicon']);
    }
}
