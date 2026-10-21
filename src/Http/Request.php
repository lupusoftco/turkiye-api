<?php

declare(strict_types=1);

namespace TurkiyeApi\Http;

final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    public string $method;
    public string $path;

    /** @var array<string, mixed> */
    public array $query;

    /** @var array<string, string> Lower-cased header names. */
    public array $headers;

    public string $body;
    public string $remoteAddr;

    /**
     * Base path the API is mounted under, e.g. "/api" (empty at the web root).
     */
    public string $basePath;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers Lower-cased header names.
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        string $body = '',
        string $remoteAddr = '127.0.0.1',
        string $basePath = ''
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->headers = $headers;
        $this->body = $body;
        $this->remoteAddr = $remoteAddr;
        $this->basePath = $basePath;
    }

    public static function fromGlobals(?string $basePathOverride = null): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');

        // When installed in a sub-directory (e.g. example.com/api/), strip that
        // prefix so routes always start at "/". It is detected from the location
        // of index.php, or set explicitly with BASE_PATH.
        $basePath = $basePathOverride ?? str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && $basePath !== '.') {
            if ($path === $basePath) {
                $path = '/';
            } elseif (str_starts_with($path, $basePath . '/')) {
                $path = substr($path, strlen($basePath));
            }
        } else {
            $basePath = '';
        }

        // Request bodies are tiny JSON documents; anything larger is rejected.
        $body = (string) file_get_contents('php://input', false, null, 0, 65537);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            $body,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            $basePath
        );
    }

    /**
     * Scheme, host and base path of this request, e.g. "https://example.com/api".
     */
    public function baseUrl(): string
    {
        $https = $this->header('x-forwarded-proto') === 'https'
            || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $host = $this->header('host') !== '' ? $this->header('host') : (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . $host . $this->basePath;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** @param array<string, string> $params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function route(string $name): string
    {
        return $this->routeParams[$name] ?? '';
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (strlen($this->body) > 65536) {
            throw new ApiException(413, 'payload-too-large', 'Request body must be at most 64 KB.');
        }
        if (trim($this->body) === '') {
            return $this->json = [];
        }
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw ApiException::badRequest('Request body must be a JSON object.');
        }
        return $this->json = $decoded;
    }

    /**
     * Reads a parameter from the JSON body (for POST) or the query string.
     */
    public function input(string $name): ?string
    {
        if ($this->method === 'POST') {
            $json = $this->json();
            if (array_key_exists($name, $json)) {
                $value = $json[$name];
                if (is_scalar($value)) {
                    return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
                throw ApiException::badRequest("`$name` must be a string or number.", $name);
            }
        }
        $value = $this->query[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::badRequest("`$name` must be given once.", $name);
        }
        return $value;
    }

    public function required(string $name): string
    {
        $value = $this->input($name);
        if ($value === null || trim($value) === '') {
            throw ApiException::badRequest("`$name` is required.", $name);
        }
        return $value;
    }

    public function int(string $name, int $default, int $min, int $max): int
    {
        $value = $this->input($name);
        if ($value === null || $value === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', trim($value))) {
            throw ApiException::badRequest("`$name` must be an integer.", $name);
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw ApiException::badRequest("`$name` must be between $min and $max.", $name);
        }
        return $int;
    }

    public function bool(string $name, bool $default): bool
    {
        $value = $this->input($name);
        if ($value === null || $value === '') {
            return $default;
        }
        $v = strtolower($value);
        if (in_array($v, ['1', 'true', 'yes', 'evet'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'hayir', 'hayır'], true)) {
            return false;
        }
        throw ApiException::badRequest("`$name` must be true or false.", $name);
    }

    public function date(string $name, ?\DateTimeImmutable $default = null): \DateTimeImmutable
    {
        $value = $this->input($name);
        if ($value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw ApiException::badRequest("`$name` is required (YYYY-MM-DD).", $name);
        }
        return self::parseDate($value, $name);
    }

    public static function parseDate(string $value, string $name = 'date'): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $tz);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ApiException::badRequest("`$name` must be a valid date in YYYY-MM-DD format.", $name);
        }
        return $date;
    }
}
