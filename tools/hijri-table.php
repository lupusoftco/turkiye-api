<?php

declare(strict_types=1);

/**
 * Prints the BAYRAM_STARTS table for HolidayCalendar (requires ext-intl).
 *   php tools/hijri-table.php [fromYear] [toYear]
 */

require __DIR__ . '/../vendor/autoload.php';

use TurkiyeApi\Modules\Holidays\HolidayCalendar;

$from = (int) ($argv[1] ?? HolidayCalendar::MIN_YEAR - 1);
$to = (int) ($argv[2] ?? HolidayCalendar::MAX_YEAR + 1);
$tz = new DateTimeZone('Europe/Istanbul');
for ($y = $from; $y <= $to; $y++) {
    $parts = [];
    foreach (['R' => [10, 1], 'K' => [12, 10]] as $suffix => [$m, $d]) {
        foreach (HolidayCalendar::hijriToGregorian($y, $m, $d, $tz) as $date) {
            $parts[] = $date->format('Y-m-d') . $suffix;
        }
    }
    sort($parts);
    printf("        %d => '%s',\n", $y, implode(' ', $parts));
}
