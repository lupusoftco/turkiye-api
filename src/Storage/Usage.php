<?php

declare(strict_types=1);

namespace TurkiyeApi\Storage;

/**
 * Daily request counters per consumer and endpoint group. No IPs, query
 * strings or bodies are stored.
 */
final class Usage
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function record(string $consumer, string $endpoint): void
    {
        $key = [gmdate('Y-m-d'), $consumer, $endpoint];
        $this->db->run('INSERT OR IGNORE INTO usage_daily (day, consumer, endpoint, hits) VALUES (?, ?, ?, 0)', $key);
        $this->db->run('UPDATE usage_daily SET hits = hits + 1 WHERE day = ? AND consumer = ? AND endpoint = ?', $key);
    }

    /** @return list<array<string, mixed>> */
    public function summary(int $days = 30): array
    {
        return $this->db->all(
            'SELECT day, endpoint, SUM(hits) AS hits, COUNT(DISTINCT consumer) AS consumers
             FROM usage_daily WHERE day >= ? GROUP BY day, endpoint ORDER BY day DESC, hits DESC',
            [gmdate('Y-m-d', time() - $days * 86400)],
        );
    }
}
