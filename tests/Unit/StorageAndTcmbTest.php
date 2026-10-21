<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurkiyeApi\Modules\Exchange\TcmbClient;
use TurkiyeApi\Storage\ApiKeys;
use TurkiyeApi\Storage\Cache;
use TurkiyeApi\Storage\Database;
use TurkiyeApi\Storage\RateLimiter;

final class StorageAndTcmbTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->db->migrate();
    }

    public function testTcmbXmlParsing(): void
    {
        $b = TcmbClient::parse((string) file_get_contents(__DIR__ . '/../fixtures/tcmb-2026-10-22.xml'));
        self::assertSame('2026-10-22', $b['date']);
        self::assertSame('2026/201', $b['bulletin_no']);
        $byCode = array_column($b['rates'], null, 'code');
        self::assertSame(41.8512, $byCode['USD']['forex_buying']);
        self::assertSame(100, $byCode['JPY']['unit']);
        self::assertSame('ÖZEL ÇEKME HAKKI (SDR)', $byCode['XDR']['name_tr']);
        self::assertNull($byCode['XDR']['forex_selling']);
    }

    public function testTcmbRejectsOtherDocuments(): void
    {
        $this->expectException(\RuntimeException::class);
        TcmbClient::parse('<html><body>Not found</body></html>');
    }

    public function testRateLimiterFixedWindow(): void
    {
        $limiter = new RateLimiter($this->db);
        $now = 1_000_020;
        for ($i = 1; $i <= 3; $i++) {
            $s = $limiter->hit('ip:x', 3, 60, $now);
            self::assertTrue($s['allowed']);
            self::assertSame(3 - $i, $s['remaining']);
        }
        self::assertFalse($limiter->hit('ip:x', 3, 60, $now)['allowed']);
        self::assertTrue($limiter->hit('ip:y', 3, 60, $now)['allowed'], 'buckets are independent');
        self::assertTrue($limiter->hit('ip:x', 3, 60, $now + 60)['allowed'], 'next window resets');
    }

    public function testApiKeysAreHashedAndRevocable(): void
    {
        $keys = new ApiKeys($this->db);
        $created = $keys->create('Test', 'dev@example.com', 1200);
        self::assertStringStartsWith('tka_', $created['key']);
        self::assertNull($this->db->one('SELECT 1 FROM api_keys WHERE key_hash = ?', [$created['key']]));

        $found = $keys->find($created['key']);
        self::assertSame(1200, $found['rate_limit'] ?? null);
        self::assertNull($keys->find('tka_wrong'));

        self::assertTrue($keys->revoke($created['id']));
        self::assertNull($keys->find($created['key']));
    }

    public function testCache(): void
    {
        $cache = new Cache($this->db);
        self::assertNull($cache->get('k'));
        $cache->set('k', ['a' => 'ş'], 60);
        self::assertSame(['a' => 'ş'], $cache->get('k'));
        $calls = 0;
        $value = $cache->remember('r', 60, function () use (&$calls) {
            $calls++;
            return 42;
        });
        $cache->remember('r', 60, function () use (&$calls) {
            $calls++;
            return 0;
        });
        self::assertSame(42, $value);
        self::assertSame(1, $calls);
        $cache->set('old', 1, -1);
        self::assertNull($cache->get('old'));
        self::assertSame(1, $cache->prune());
    }
}
