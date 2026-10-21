<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Validation;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;

/**
 * Validation endpoints accept POST bodies only, so identity and account
 * numbers never appear in URLs, access logs or browser history.
 *
 * Body: {"value": "..."} or {"values": ["...", "..."]} (up to 100).
 */
final class ValidationController
{
    private const MAX_BATCH = 100;

    /** @var array<int|string, string>|null */
    private ?array $cities = null;

    private string $citiesCsv;

    public function __construct(string $citiesCsv)
    {
        $this->citiesCsv = $citiesCsv;
    }

    public function handle(Request $request): Response
    {
        $type = $request->route('type');
        $validators = [
            'tckn' => static fn (string $v) => Validators::tckn($v),
            'vkn' => static fn (string $v) => Validators::vkn($v),
            'iban' => static fn (string $v) => Validators::iban($v),
            'phone' => static fn (string $v) => Validators::phone($v),
            'plate' => fn (string $v) => Validators::plate($v, $this->cities()),
        ];
        if (!isset($validators[$type])) {
            throw ApiException::notFound('Unknown validator. Use tckn, vkn, iban, phone or plate.');
        }
        $validator = $validators[$type];

        $body = $request->json();
        if (array_key_exists('values', $body)) {
            if (!is_array($body['values']) || !array_is_list($body['values'])) {
                throw ApiException::badRequest('`values` must be an array of strings.', 'values');
            }
            if (count($body['values']) > self::MAX_BATCH) {
                throw ApiException::badRequest('At most ' . self::MAX_BATCH . ' values per request.', 'values');
            }
            $results = array_map(static function ($v) use ($validator) {
                if (!is_string($v) && !is_int($v)) {
                    throw ApiException::badRequest('Every item in `values` must be a string.', 'values');
                }
                return ['input' => (string) $v] + $validator(self::limit((string) $v));
            }, $body['values']);
            return Response::data($results, [
                'count' => count($results),
                'valid_count' => count(array_filter($results, static fn ($r) => $r['valid'])),
            ]);
        }

        $value = $body['value'] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw ApiException::badRequest('Send {"value": "..."} or {"values": [...]} as JSON.', 'value');
        }
        return Response::data($validator(self::limit((string) $value)), $type === 'tckn' ? [
            'note' => 'Checksum validation only; it does not confirm that the number belongs to a real person.',
        ] : []);
    }

    private static function limit(string $v): string
    {
        if (strlen($v) > 64) {
            throw ApiException::badRequest('Values must be at most 64 characters.', 'value');
        }
        return $v;
    }

    /** @return array<int|string, string> */
    private function cities(): array
    {
        if ($this->cities !== null) {
            return $this->cities;
        }
        $this->cities = [];
        if (($fh = @fopen($this->citiesCsv, 'rb')) !== false) {
            fgetcsv($fh, 0, ',', '"', '');
            while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if (is_array($row) && count($row) >= 2) {
                    $this->cities[(string) $row[0]] = (string) $row[1];
                }
            }
            fclose($fh);
        }
        return $this->cities;
    }
}
