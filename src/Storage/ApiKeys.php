<?php

declare(strict_types=1);

namespace TurkiyeApi\Storage;

/**
 * API keys are stored only as SHA-256 hashes; the plain key is shown once
 * when it is created.
 */
final class ApiKeys
{
    public const PREFIX = 'tka_';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{id: int, key: string}
     */
    public function create(string $name, string $email = '', ?int $rateLimit = null): array
    {
        $key = self::PREFIX . bin2hex(random_bytes(20));
        $this->db->run(
            'INSERT INTO api_keys (key_hash, prefix, name, email, rate_limit, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [hash('sha256', $key), substr($key, 0, 10), $name, $email, $rateLimit, gmdate('c')],
        );
        return ['id' => (int) $this->db->pdo->lastInsertId(), 'key' => $key];
    }

    /**
     * @return array{id: int, name: string, rate_limit: ?int}|null
     */
    public function find(string $key): ?array
    {
        if (!str_starts_with($key, self::PREFIX)) {
            return null;
        }
        $row = $this->db->one(
            'SELECT id, name, rate_limit FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL',
            [hash('sha256', $key)],
        );
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'rate_limit' => $row['rate_limit'] === null ? null : (int) $row['rate_limit'],
        ];
    }

    public function revoke(int $id): bool
    {
        return $this->db->run('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [gmdate('c'), $id]) > 0;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->all('SELECT id, prefix, name, email, rate_limit, created_at, revoked_at FROM api_keys ORDER BY id');
    }
}
