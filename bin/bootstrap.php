<?php

declare(strict_types=1);

use TurkiyeApi\App;
use TurkiyeApi\Config;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

/**
 * Parses --name=value and --flag options.
 *
 * @param list<string> $argv
 * @return array<string, string|true>
 */
function cli_options(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $arg, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        }
    }
    return $opts;
}

return new App(Config::load($root));
