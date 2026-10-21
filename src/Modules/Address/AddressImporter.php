<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Address;

use TurkiyeApi\Modules\Text\TurkishText;
use TurkiyeApi\Storage\Database;

/**
 * Loads data/address/*.csv into SQLite. Re-running replaces existing data.
 */
final class AddressImporter
{
    private Database $db;
    private string $dataDir;

    public function __construct(Database $db, string $dataDir)
    {
        $this->db = $db;
        $this->dataDir = $dataDir;
    }

    /** @return array{cities: int, districts: int, neighbourhoods: int, distances: int} */
    public function import(): array
    {
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM neighbourhoods; DELETE FROM districts; DELETE FROM cities; DELETE FROM city_distances;');

            $cityStmt = $pdo->prepare('INSERT INTO cities (code, name, slug) VALUES (?, ?, ?)');
            $cities = 0;
            foreach ($this->rows('cities.csv') as [$code, $name]) {
                $cityStmt->execute([$code, $name, TurkishText::slug($name)]);
                $cities++;
            }

            $districtStmt = $pdo->prepare('INSERT INTO districts (id, city_code, name, slug) VALUES (?, ?, ?, ?)');
            $nbStmt = $pdo->prepare('INSERT INTO neighbourhoods (id, district_id, name, postal_code, search) VALUES (?, ?, ?, ?, ?)');
            $districtIds = [];
            $nextDistrict = 1;
            $nextNb = 1;
            foreach ($this->rows('neighbourhoods.csv') as [$cityCode, $district, $neighbourhood, $postal]) {
                $key = $cityCode . '|' . $district;
                if (!isset($districtIds[$key])) {
                    $districtIds[$key] = $nextDistrict;
                    $districtStmt->execute([$nextDistrict, $cityCode, $district, TurkishText::slug($district)]);
                    $nextDistrict++;
                }
                $nbStmt->execute([$nextNb++, $districtIds[$key], $neighbourhood, $postal, TurkishText::searchKey($neighbourhood)]);
            }

            $distStmt = $pdo->prepare('INSERT INTO city_distances (a, b, km) VALUES (?, ?, ?)');
            $distances = 0;
            foreach ($this->rows('distances.csv') as [$a, $b, $km]) {
                $distStmt->execute([$a, $b, (int) $km]);
                $distances++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [
            'cities' => $cities,
            'districts' => $nextDistrict - 1,
            'neighbourhoods' => $nextNb - 1,
            'distances' => $distances,
        ];
    }

    /** @return \Generator<list<string>> */
    private function rows(string $file): \Generator
    {
        $path = $this->dataDir . '/' . $file;
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open $path");
        }
        fgetcsv($fh, 0, ',', '"', ''); // header
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            // Skip blank lines (fgetcsv returns [null]) and treat missing cells as empty.
            if ($row === null || $row === [null]) {
                continue;
            }
            yield array_map(static fn ($cell): string => (string) $cell, $row);
        }
        fclose($fh);
    }
}
