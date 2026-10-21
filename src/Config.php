<?php

declare(strict_types=1);

namespace TurkiyeApi;

/**
 * Environment-driven configuration. Values come from real environment
 * variables first, then from a `.env` file in the project root.
 */
final class Config
{
    /** @var array<string, string> */
    private array $values;

    /** @param array<string, string> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function load(string $rootDir): self
    {
        $values = [];
        $envFile = $rootDir . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $value = trim($value);
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                $values[trim($key)] = $value;
            }
        }
        foreach ($_ENV + getenv() as $key => $value) {
            if (is_string($value)) {
                $values[(string) $key] = $value;
            }
        }
        $values['ROOT_DIR'] = $rootDir;

        return new self($values);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';
        return $value === '' ? $default : $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? '';
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = strtolower($this->values[$key] ?? '');
        if ($value === '') {
            return $default;
        }
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public function list(string $key, string $default = ''): array
    {
        $raw = $this->string($key, $default);
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== ''));
    }

    public function rootDir(): string
    {
        return $this->values['ROOT_DIR'] ?? dirname(__DIR__);
    }

    public function path(string $key, string $default): string
    {
        $path = $this->string($key, $default);
        return str_starts_with($path, '/') ? $path : $this->rootDir() . '/' . $path;
    }
}
