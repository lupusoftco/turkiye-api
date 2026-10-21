<?php

declare(strict_types=1);

namespace TurkiyeApi\Storage;

final class Cache
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return mixed */
    public function get(string $key)
    {
        $row = $this->db->one('SELECT value, expires_at FROM cache WHERE key = ?', [$key]);
        if ($row === null || (int) $row['expires_at'] < time()) {
            return null;
        }
        return json_decode((string) $row['value'], true);
    }

    /** @param mixed $value */
    public function set(string $key, $value, int $ttl): void
    {
        $this->db->run(
            'INSERT OR REPLACE INTO cache (key, value, expires_at) VALUES (?, ?, ?)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), time() + $ttl],
        );
    }

    /**
     * @template T
     * @param callable(): T $producer
     * @return T
     */
    public function remember(string $key, int $ttl, callable $producer)
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $producer();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function prune(): int
    {
        return $this->db->run('DELETE FROM cache WHERE expires_at < ?', [time()]);
    }
}
