<?php

declare(strict_types=1);

/**
 * Prints request counts per day and endpoint group.
 *   php bin/stats.php [--days=7]
 */

use TurkiyeApi\Storage\Usage;

/** @var \TurkiyeApi\App $app */
$app = require __DIR__ . '/bootstrap.php';
$opts = cli_options($argv);
$days = isset($opts['days']) && is_numeric($opts['days']) ? (int) $opts['days'] : 7;

$rows = (new Usage($app->db()))->summary($days);
if ($rows === []) {
    echo "No requests recorded in the last $days day(s).\n";
    exit(0);
}
printf("%-12s %-18s %10s %10s\n", 'day', 'endpoint', 'requests', 'consumers');
foreach ($rows as $r) {
    printf("%-12s %-18s %10d %10d\n", $r['day'], $r['endpoint'], $r['hits'], $r['consumers']);
}
