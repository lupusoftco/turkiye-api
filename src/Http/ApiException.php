<?php

declare(strict_types=1);

namespace TurkiyeApi\Http;

/**
 * An error that is safe to show to API clients. Rendered as
 * RFC 9457 `application/problem+json`.
 */
final class ApiException extends \RuntimeException
{
    /** @var int */
    public $status;

    /** @var string */
    public $type;

    /** @var array<string, mixed> Additional members merged into the problem document. */
    public $extra;

    /** @var array<string, string> */
    public $headers;

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public function __construct(int $status, string $type, string $detail, array $extra = [], array $headers = [])
    {
        parent::__construct($detail, $status);
        $this->status = $status;
        $this->type = $type;
        $this->extra = $extra;
        $this->headers = $headers;
    }

    public static function badRequest(string $detail, string $param = ''): self
    {
        return new self(400, 'invalid-parameter', $detail, $param !== '' ? ['parameter' => $param] : []);
    }

    public static function notFound(string $detail): self
    {
        return new self(404, 'not-found', $detail);
    }

    public static function unprocessable(string $detail): self
    {
        return new self(422, 'unprocessable', $detail);
    }

    public static function upstream(string $detail): self
    {
        return new self(502, 'upstream-error', $detail);
    }

    public function title(): string
    {
        $titles = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            413 => 'Payload Too Large',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];
        return $titles[$this->status] ?? 'Error';
    }
}
