<?php

declare(strict_types=1);

/**
 * Creates the SQLite database and imports address data.
 *   php bin/setup.php
 */

use TurkiyeApi\Modules\Address\AddressImporter;

/** @var \TurkiyeApi\App $app */
$app = require __DIR__ . '/bootstrap.php';

$db = $app->db();
echo "Database ready: " . $app->config->path('DB_PATH', 'storage/database.sqlite') . "\n";

$started = microtime(true);
$stats = (new AddressImporter($db, $app->config->rootDir() . '/data/address'))->import();
printf(
    "Imported %d provinces, %d districts, %d neighbourhoods, %d distances in %.1fs\n",
    $stats['cities'],
    $stats['districts'],
    $stats['neighbourhoods'],
    $stats['distances'],
    microtime(true) - $started,
);
$db->pdo->exec('VACUUM');
echo "Done. Next: schedule `php bin/fetch-rates.php` on weekdays after 15:30 (Europe/Istanbul).\n";
