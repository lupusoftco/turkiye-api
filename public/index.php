<?php

declare(strict_types=1);

/*
 * Front controller.
 *
 * Works in two layouts:
 *  - repository layout: this file in public/, application one level up;
 *  - upload layout (dist/api): this file next to an app/ directory, so the
 *    whole API can live in a sub-directory such as example.com/api/.
 */

$root = is_dir(__DIR__ . '/app/src') ? __DIR__ . '/app' : dirname(__DIR__);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Türkiye API needs PHP 7.4 or newer; this server runs ' . PHP_VERSION . '.']);
    return;
}

$missing = array_values(array_filter(
    ['pdo_sqlite', 'mbstring', 'json', 'curl', 'dom', 'simplexml'],
    static fn (string $ext) => !extension_loaded($ext)
));
if ($missing !== []) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing PHP extensions: ' . implode(', ', $missing) . '. Enable them in the PHP settings of this domain.']);
    return;
}

// Let the PHP built-in server (composer serve) deliver static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && $file !== __FILE__) {
        return false;
    }
    if (is_dir($file) && is_file(rtrim($file, '/') . '/index.html')) {
        readfile(rtrim($file, '/') . '/index.html');
        return true;
    }
}

require $root . '/vendor/autoload.php';

$config = TurkiyeApi\Config::load($root);
$request = TurkiyeApi\Http\Request::fromGlobals($config->string('BASE_PATH', '') !== '' ? $config->string('BASE_PATH') : null);
$app = new TurkiyeApi\App($config);
$app->handle($request)->send($request->method === 'HEAD');
