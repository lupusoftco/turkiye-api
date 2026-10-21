<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Exchange;

use TurkiyeApi\Storage\Database;

/**
 * Stores TCMB bulletins in SQLite and answers rate, history and conversion
 * queries. Bulletins are fetched on demand (one per missing day) and by
 * the `bin/fetch-rates.php` cron job.
 */
final class ExchangeService
{
    public const RATE_TYPES = ['forex_buying', 'forex_selling', 'banknote_buying', 'banknote_selling'];

    /** How far back to look for the most recent published bulletin. */
    private const MAX_LOOKBACK_DAYS = 10;

    private const PENDING_RECHECK_SECONDS = 900;

    private \DateTimeZone $tz;
    private Database $db;
    private TcmbClient $client;
    private bool $fetchOnDemand;

    public function __construct(Database $db, TcmbClient $client, bool $fetchOnDemand = true)
    {
        $this->db = $db;
        $this->client = $client;
        $this->fetchOnDemand = $fetchOnDemand;
        $this->tz = new \DateTimeZone('Europe/Istanbul');
    }

    /**
     * Bulletin valid for $date: the one published that day, or the most
     * recent earlier one (TCMB publishes nothing on weekends and holidays).
     *
     * @return array{date: string, bulletin_no: ?string, rates: list<array<string, mixed>>}|null
     */
    public function bulletinFor(\DateTimeImmutable $date): ?array
    {
        $today = new \DateTimeImmutable('today', $this->tz);
        if ($date > $today) {
            $date = $today;
        }
        for ($i = 0; $i <= self::MAX_LOOKBACK_DAYS; $i++) {
            $day = $date->modify("-$i day");
            $bulletin = $this->load($day);
            if ($bulletin !== null) {
                return $bulletin;
            }
        }
        return null;
    }

    /**
     * Daily rates for one currency between two dates (only days with a bulletin).
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $code, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($this->fetchOnDemand) {
            $this->backfill($from, $to, 40);
        }
        return array_map(
            static fn (array $r) => self::castRate($r),
            $this->db->all(
                'SELECT date, code, unit, name_tr, name_en, forex_buying, forex_selling, banknote_buying, banknote_selling
                 FROM fx_rates WHERE code = ? AND date BETWEEN ? AND ? ORDER BY date',
                [$code, $from->format('Y-m-d'), $to->format('Y-m-d')],
            ),
        );
    }

    /**
     * Fetches missing bulletins in a range (newest first), at most $limit
     * network requests per call so a single API request stays fast.
     *
     * @return int Number of days fetched.
     */
    public function backfill(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = PHP_INT_MAX): int
    {
        $known = array_column($this->db->all(
            'SELECT date FROM fx_bulletins WHERE date BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')],
        ), 'date', 'date');
        $today = new \DateTimeImmutable('today', $this->tz);
        $fetched = 0;
        for ($d = min($to, $today); $d >= $from && $fetched < $limit; $d = $d->modify('-1 day')) {
            if (isset($known[$d->format('Y-m-d')]) || (int) $d->format('N') >= 6) {
                continue;
            }
            $this->fetchAndStore($d);
            $fetched++;
        }
        return $fetched;
    }

    /**
     * Converts an amount using TCMB rates (cross rates go through TRY).
     *
     * @return array{amount: float, result: float, rate: float, from: string, to: string, rate_type: string, date: string}
     */
    public function convert(float $amount, string $from, string $to, string $rateType, \DateTimeImmutable $date): array
    {
        $bulletin = $this->bulletinFor($date);
        if ($bulletin === null) {
            throw new \RuntimeException('No TCMB bulletin is available for this date.');
        }
        $perUnitTry = function (string $code) use ($bulletin, $rateType): float {
            if ($code === 'TRY') {
                return 1.0;
            }
            foreach ($bulletin['rates'] as $r) {
                if ($r['code'] === $code) {
                    $value = $r[$rateType];
                    if ($value === null) {
                        throw new \DomainException("TCMB does not publish a $rateType rate for $code.");
                    }
                    return $value / $r['unit'];
                }
            }
            throw new \DomainException("Unknown currency: $code.");
        };
        $rate = $perUnitTry($from) / $perUnitTry($to);
        return [
            'amount' => $amount,
            'result' => round($amount * $rate, 4),
            'rate' => round($rate, 8),
            'from' => $from,
            'to' => $to,
            'rate_type' => $rateType,
            'date' => $bulletin['date'],
        ];
    }

    /**
     * Fetches a bulletin from TCMB and stores it. Pass null for "today".
     *
     * @return array{date: string, bulletin_no: ?string, rates: list<array<string, mixed>>}|null
     */
    public function fetchAndStore(?\DateTimeImmutable $date): ?array
    {
        $bulletin = $this->client->fetch($date);
        $now = gmdate('c');
        if ($bulletin === null) {
            // Remember "no bulletin" for past days so we never ask TCMB again;
            // today's bulletin may still be published later (around 15:30).
            // Today is marked 'pending' and re-checked at most every 15 minutes.
            $today = (new \DateTimeImmutable('today', $this->tz))->format('Y-m-d');
            $day = ($date ?? new \DateTimeImmutable('today', $this->tz))->format('Y-m-d');
            $this->db->run(
                'INSERT OR REPLACE INTO fx_bulletins (date, bulletin_no, status, fetched_at) VALUES (?, NULL, ?, ?)',
                [$day, $day < $today ? 'none' : 'pending', $now],
            );
            return null;
        }

        $this->db->pdo->beginTransaction();
        try {
            $this->db->run(
                "INSERT OR REPLACE INTO fx_bulletins (date, bulletin_no, status, fetched_at) VALUES (?, ?, 'ok', ?)",
                [$bulletin['date'], $bulletin['bulletin_no'], $now],
            );
            $stmt = $this->db->pdo->prepare(
                'INSERT OR REPLACE INTO fx_rates
                 (date, code, unit, name_tr, name_en, forex_buying, forex_selling, banknote_buying, banknote_selling)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            foreach ($bulletin['rates'] as $r) {
                $stmt->execute([
                    $bulletin['date'], $r['code'], $r['unit'], $r['name_tr'], $r['name_en'],
                    $r['forex_buying'], $r['forex_selling'], $r['banknote_buying'], $r['banknote_selling'],
                ]);
            }
            // A request for a past date can return an earlier bulletin; mark the asked day too.
            if ($date !== null && $date->format('Y-m-d') !== $bulletin['date']) {
                $this->db->run(
                    "INSERT OR IGNORE INTO fx_bulletins (date, bulletin_no, status, fetched_at) VALUES (?, NULL, 'none', ?)",
                    [$date->format('Y-m-d'), $now],
                );
            }
            $this->db->pdo->commit();
        } catch (\Throwable $e) {
            $this->db->pdo->rollBack();
            throw $e;
        }
        return $bulletin;
    }

    /**
     * @return array{date: string, bulletin_no: ?string, rates: list<array<string, mixed>>}|null
     */
    private function load(\DateTimeImmutable $day): ?array
    {
        $key = $day->format('Y-m-d');
        $meta = $this->db->one('SELECT date, bulletin_no, status, fetched_at FROM fx_bulletins WHERE date = ?', [$key]);
        $stalePending = $meta !== null && $meta['status'] === 'pending'
            && strtotime((string) $meta['fetched_at']) < time() - self::PENDING_RECHECK_SECONDS;
        if ($meta === null || $stalePending) {
            if (!$this->fetchOnDemand || (int) $day->format('N') >= 6) {
                return null;
            }
            $this->fetchAndStore($day);
            $meta = $this->db->one('SELECT date, bulletin_no, status FROM fx_bulletins WHERE date = ?', [$key]);
        }
        if ($meta === null || $meta['status'] !== 'ok') {
            return null;
        }
        $rates = $this->db->all(
            'SELECT code, unit, name_tr, name_en, forex_buying, forex_selling, banknote_buying, banknote_selling
             FROM fx_rates WHERE date = ? ORDER BY code',
            [$key],
        );
        return [
            'date' => $key,
            'bulletin_no' => $meta['bulletin_no'] === null ? null : (string) $meta['bulletin_no'],
            'rates' => array_map(static fn (array $r) => self::castRate($r), $rates),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function castRate(array $r): array
    {
        foreach (self::RATE_TYPES as $t) {
            $r[$t] = $r[$t] === null ? null : (float) $r[$t];
        }
        $r['unit'] = (int) $r['unit'];
        return $r;
    }
}
