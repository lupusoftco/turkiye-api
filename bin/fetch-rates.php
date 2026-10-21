<?php

declare(strict_types=1);

/**
 * Fetches TCMB exchange-rate bulletins into the database.
 *
 *   php bin/fetch-rates.php                      # today's bulletin
 *   php bin/fetch-rates.php --from=2025-01-01    # backfill from a date to today
 *   php bin/fetch-rates.php --from=2025-01-01 --to=2025-03-31
 *
 * Suggested cron (server time Europe/Istanbul):
 *   45 15,16 * * 1-5  php /path/to/bin/fetch-rates.php
 */

/** @var \TurkiyeApi\App $app */
$app = require __DIR__ . '/bootstrap.php';
$opts = cli_options($argv);
$service = $app->exchangeService();
$tz = new DateTimeZone('Europe/Istanbul');

try {
    if (isset($opts['from'])) {
        $from = new DateTimeImmutable((string) $opts['from'], $tz);
        $to = new DateTimeImmutable(is_string($opts['to'] ?? null) ? $opts['to'] : 'today', $tz);
        $count = $service->backfill($from, $to);
        echo "Checked $count weekday(s) between {$from->format('Y-m-d')} and {$to->format('Y-m-d')}.\n";
        exit(0);
    }
    $bulletin = $service->fetchAndStore(null);
    if ($bulletin === null) {
        echo "No bulletin published yet.\n";
        exit(0);
    }
    printf("Stored bulletin %s (%s) with %d currencies.\n", $bulletin['date'], $bulletin['bulletin_no'], count($bulletin['rates']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
