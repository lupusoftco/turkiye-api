<?php

declare(strict_types=1);

/**
 * Converts the turkey-neighbourhoods JSON data into the CSV files in data/address.
 *   php tools/build-address-data.php <package/src/data> <data/address>
 */

[$script, $src, $dst] = $argv + [null, null, null];
if ($src === null || $dst === null) {
    fwrite(STDERR, "Usage: php tools/build-address-data.php <source-data-dir> <target-dir>\n");
    exit(1);
}

$read = static fn (string $file) => json_decode((string) file_get_contents("$src/$file"), true, 512, JSON_THROW_ON_ERROR);
$write = static function (string $file, array $header, iterable $rows) use ($dst): int {
    $fh = fopen("$dst/$file", 'wb');
    fputcsv($fh, $header, ',', '"', '');
    $n = 0;
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '');
        $n++;
    }
    fclose($fh);
    return $n;
};

$cities = $read('cityList.json');
usort($cities, static fn ($a, $b) => strcmp($a['code'], $b['code']));
$c = $write('cities.csv', ['code', 'name'], array_map(static fn ($x) => [$x['code'], $x['name']], $cities));

$n = $write('neighbourhoods.csv', ['city_code', 'district', 'neighbourhood', 'postal_code'], (static function () use ($read) {
    foreach ($read('neighbourhoods.json') as [$code, , $district, $name, $postal]) {
        yield [$code, $district, $name, $postal];
    }
})());

$distances = $read('distances.json');
$d = $write('distances.csv', ['from', 'to', 'km'], (static function () use ($distances) {
    $codes = array_keys($distances);
    sort($codes, SORT_STRING);
    foreach ($codes as $a) {
        $targets = array_keys($distances[$a]);
        sort($targets, SORT_STRING);
        foreach ($targets as $b) {
            if (strcmp((string) $a, (string) $b) < 0) {
                yield [$a, $b, $distances[$a][$b]];
            }
        }
    }
})());

echo "Wrote $c provinces, $n neighbourhoods, $d distances.\n";
