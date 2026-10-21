<?php

declare(strict_types=1);

/**
 * Manages API keys.
 *
 *   php bin/api-key.php create --name="Acme" [--email=dev@acme.com] [--limit=1200]
 *   php bin/api-key.php list
 *   php bin/api-key.php revoke --id=3
 */

use TurkiyeApi\Storage\ApiKeys;

/** @var \TurkiyeApi\App $app */
$app = require __DIR__ . '/bootstrap.php';
$opts = cli_options($argv);
$keys = new ApiKeys($app->db());

switch ($argv[1] ?? '') {
    case 'create':
        if (!is_string($opts['name'] ?? null) || $opts['name'] === '') {
            fwrite(STDERR, "--name is required\n");
            exit(1);
        }
        $limit = isset($opts['limit']) && is_numeric($opts['limit']) ? (int) $opts['limit'] : null;
        $created = $keys->create($opts['name'], is_string($opts['email'] ?? null) ? $opts['email'] : '', $limit);
        echo "Key #{$created['id']} created. Store it now; it cannot be shown again:\n\n  {$created['key']}\n\n";
        break;
    case 'list':
        foreach ($keys->list() as $k) {
            printf(
                "#%-4d %-12s %-24s limit=%-6s created=%s%s\n",
                $k['id'],
                $k['prefix'] . '…',
                $k['name'],
                $k['rate_limit'] ?? 'default',
                substr((string) $k['created_at'], 0, 10),
                $k['revoked_at'] ? '  REVOKED' : '',
            );
        }
        break;
    case 'revoke':
        $id = (int) ($opts['id'] ?? 0);
        echo $keys->revoke($id) ? "Key #$id revoked.\n" : "No active key #$id.\n";
        break;
    default:
        echo "Usage: php bin/api-key.php create|list|revoke [options]\n";
        exit(1);
}
