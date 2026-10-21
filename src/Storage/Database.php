<?php

declare(strict_types=1);

namespace TurkiyeApi\Storage;

/**
 * SQLite connection plus idempotent schema migrations. A single file keeps
 * the API easy to host on shared/Plesk servers with no database server.
 */
final class Database
{
    private const SCHEMA_VERSION = 1;

    public \PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if ($path !== ':memory:' && !is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create storage directory: $dir");
        }
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = 3000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function migrate(): void
    {
        $stmt = $this->pdo->query('PRAGMA user_version');
        $version = $stmt === false ? 0 : (int) $stmt->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL,
                PRIMARY KEY (bucket, window_start)
            );
            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash TEXT NOT NULL UNIQUE,
                prefix TEXT NOT NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL DEFAULT '',
                rate_limit INTEGER,
                created_at TEXT NOT NULL,
                revoked_at TEXT
            );
            CREATE TABLE IF NOT EXISTS usage_daily (
                day TEXT NOT NULL,
                consumer TEXT NOT NULL,
                endpoint TEXT NOT NULL,
                hits INTEGER NOT NULL,
                PRIMARY KEY (day, consumer, endpoint)
            );
            CREATE TABLE IF NOT EXISTS fx_bulletins (
                date TEXT PRIMARY KEY,
                bulletin_no TEXT,
                status TEXT NOT NULL,
                fetched_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS fx_rates (
                date TEXT NOT NULL,
                code TEXT NOT NULL,
                unit INTEGER NOT NULL,
                name_tr TEXT NOT NULL,
                name_en TEXT NOT NULL,
                forex_buying REAL,
                forex_selling REAL,
                banknote_buying REAL,
                banknote_selling REAL,
                PRIMARY KEY (date, code)
            );
            CREATE INDEX IF NOT EXISTS fx_rates_code_date ON fx_rates (code, date);
            CREATE TABLE IF NOT EXISTS cities (
                code TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE
            );
            CREATE TABLE IF NOT EXISTS districts (
                id INTEGER PRIMARY KEY,
                city_code TEXT NOT NULL REFERENCES cities(code),
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                UNIQUE (city_code, slug)
            );
            CREATE TABLE IF NOT EXISTS neighbourhoods (
                id INTEGER PRIMARY KEY,
                district_id INTEGER NOT NULL REFERENCES districts(id),
                name TEXT NOT NULL,
                postal_code TEXT NOT NULL,
                search TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS neighbourhoods_district ON neighbourhoods (district_id);
            CREATE INDEX IF NOT EXISTS neighbourhoods_postal ON neighbourhoods (postal_code);
            CREATE INDEX IF NOT EXISTS neighbourhoods_search ON neighbourhoods (search);
            CREATE TABLE IF NOT EXISTS city_distances (
                a TEXT NOT NULL,
                b TEXT NOT NULL,
                km INTEGER NOT NULL,
                PRIMARY KEY (a, b)
            );
        SQL);
        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_values($stmt->fetchAll() ?: []);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
