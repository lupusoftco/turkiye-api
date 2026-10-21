<?php

declare(strict_types=1);

namespace TurkiyeApi\Http;

final class Response
{
    public string $body;
    public int $status;

    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(string $body, int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /**
     * Standard success envelope: `{ "data": ..., "meta": {...} }`.
     *
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    public static function data($data, array $meta = [], int $maxAge = 0): self
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        $response = self::json($payload);
        $response->headers['Cache-Control'] = $maxAge > 0 ? "public, max-age=$maxAge" : 'no-store';
        return $response;
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function problem(ApiException $e, string $instance): self
    {
        $payload = [
            'type' => 'https://github.com/lupusoftco/turkiye-api/blob/main/docs/errors.md#' . $e->type,
            'title' => $e->title(),
            'status' => $e->status,
            'detail' => $e->getMessage(),
            'instance' => $instance,
        ] + $e->extra;
        $response = self::json($payload, $e->status);
        $response->headers['Content-Type'] = 'application/problem+json; charset=utf-8';
        $response->headers['Cache-Control'] = 'no-store';
        foreach ($e->headers as $name => $value) {
            $response->headers[$name] = $value;
        }
        return $response;
    }

    public static function raw(string $body, string $contentType, int $maxAge = 0): self
    {
        return new self($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => $maxAge > 0 ? "public, max-age=$maxAge" : 'no-store',
        ]);
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if (!$headOnly) {
            echo $this->body;
        }
    }
}
