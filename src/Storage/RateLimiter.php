<?php

declare(strict_types=1);

namespace TurkiyeApi\Storage;

/**
 * Fixed-window rate limiter stored in SQLite.
 */
final class RateLimiter
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Records a hit and returns the state of the window after it.
     *
     * @return array{allowed: bool, limit: int, remaining: int, reset: int}
     */
    public function hit(string $bucket, int $limit, int $windowSeconds, ?int $now = null): array
    {
        $now ??= time();
        $windowStart = $now - ($now % $windowSeconds);
        $reset = $windowStart + $windowSeconds;

        // Plain INSERT OR IGNORE + UPDATE instead of UPSERT, which needs SQLite 3.24+.
        $this->db->run('INSERT OR IGNORE INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 0)', [$bucket, $windowStart]);
        $this->db->run('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND window_start = ?', [$bucket, $windowStart]);
        $hits = (int) ($this->db->one(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?',
            [$bucket, $windowStart],
        )['hits'] ?? 1);

        // Occasionally drop old windows so the table stays small.
        if (random_int(1, 200) === 1) {
            $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }

        return [
            'allowed' => $hits <= $limit,
            'limit' => $limit,
            'remaining' => max(0, $limit - $hits),
            'reset' => $reset,
        ];
    }
}
