<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Address;

use TurkiyeApi\Modules\Text\TurkishText;
use TurkiyeApi\Storage\Database;

final class AddressRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function isImported(): bool
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM cities')['n'] ?? 0) > 0;
    }

    /** @return list<array{code: string, name: string, slug: string}> */
    public function cities(): array
    {
        /** @var list<array{code: string, name: string, slug: string}> */
        return $this->db->all('SELECT code, name, slug FROM cities ORDER BY code');
    }

    /**
     * Accepts a plate code ("34", "6") or a name/slug ("istanbul", "İstanbul").
     *
     * @return array{code: string, name: string, slug: string}|null
     */
    public function city(string $codeOrName): ?array
    {
        $v = trim($codeOrName);
        if (preg_match('/^\d{1,2}$/', $v)) {
            /** @var array{code: string, name: string, slug: string}|null */
            return $this->db->one('SELECT code, name, slug FROM cities WHERE code = ?', [str_pad($v, 2, '0', STR_PAD_LEFT)]);
        }
        /** @var array{code: string, name: string, slug: string}|null */
        return $this->db->one('SELECT code, name, slug FROM cities WHERE slug = ?', [TurkishText::slug($v)]);
    }

    /** @return list<array{id: int, name: string, slug: string, neighbourhood_count: int}> */
    public function districts(string $cityCode): array
    {
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'neighbourhood_count' => (int) $r['n'],
        ], $this->db->all(
            'SELECT d.id, d.name, d.slug, COUNT(n.id) AS n FROM districts d
             LEFT JOIN neighbourhoods n ON n.district_id = d.id
             WHERE d.city_code = ? GROUP BY d.id ORDER BY d.slug',
            [$cityCode],
        ));
    }

    /**
     * @return array{id: int, name: string, slug: string, city_code: string, city: string}|null
     */
    public function district(int $id): ?array
    {
        $r = $this->db->one(
            'SELECT d.id, d.name, d.slug, d.city_code, c.name AS city FROM districts d JOIN cities c ON c.code = d.city_code WHERE d.id = ?',
            [$id],
        );
        return $r === null ? null : [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'city_code' => (string) $r['city_code'],
            'city' => (string) $r['city'],
        ];
    }

    public function districtBySlug(string $cityCode, string $name): ?int
    {
        $r = $this->db->one('SELECT id FROM districts WHERE city_code = ? AND slug = ?', [$cityCode, TurkishText::slug($name)]);
        return $r === null ? null : (int) $r['id'];
    }

    /** @return list<array{id: int, name: string, postal_code: string}> */
    public function neighbourhoods(int $districtId): array
    {
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'postal_code' => (string) $r['postal_code'],
        ], $this->db->all(
            'SELECT id, name, postal_code FROM neighbourhoods WHERE district_id = ? ORDER BY search',
            [$districtId],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function byPostalCode(string $postalCode): array
    {
        return $this->withHierarchy($this->db->all(
            'SELECT n.id, n.name, n.postal_code, d.id AS district_id, d.name AS district, c.code AS city_code, c.name AS city
             FROM neighbourhoods n JOIN districts d ON d.id = n.district_id JOIN cities c ON c.code = d.city_code
             WHERE n.postal_code = ? ORDER BY d.slug, n.search',
            [$postalCode],
        ));
    }

    /**
     * Prefix/substring search over neighbourhood names, ignoring case and
     * Turkish characters ("cihangir" finds "Cihangir Mah").
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, ?string $cityCode, ?int $districtId, int $limit): array
    {
        $key = TurkishText::searchKey($query);
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key);
        $where = "n.search LIKE ? ESCAPE '\\'";
        $params = ["%$like%"];
        if ($cityCode !== null) {
            $where .= ' AND d.city_code = ?';
            $params[] = $cityCode;
        }
        if ($districtId !== null) {
            $where .= ' AND d.id = ?';
            $params[] = $districtId;
        }
        $params[] = "$like%";
        $params[] = $limit;
        return $this->withHierarchy($this->db->all(
            "SELECT n.id, n.name, n.postal_code, d.id AS district_id, d.name AS district, c.code AS city_code, c.name AS city
             FROM neighbourhoods n JOIN districts d ON d.id = n.district_id JOIN cities c ON c.code = d.city_code
             WHERE $where
             ORDER BY (n.search LIKE ? ESCAPE '\\') DESC, length(n.search), c.code, d.slug
             LIMIT ?",
            $params,
        ));
    }

    public function distance(string $a, string $b): ?int
    {
        if ($a === $b) {
            return 0;
        }
        [$x, $y] = $a < $b ? [$a, $b] : [$b, $a];
        $r = $this->db->one('SELECT km FROM city_distances WHERE a = ? AND b = ?', [$x, $y]);
        return $r === null ? null : (int) $r['km'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withHierarchy(array $rows): array
    {
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'postal_code' => (string) $r['postal_code'],
            'district' => ['id' => (int) $r['district_id'], 'name' => (string) $r['district']],
            'city' => ['code' => (string) $r['city_code'], 'name' => (string) $r['city']],
        ], $rows);
    }
}
