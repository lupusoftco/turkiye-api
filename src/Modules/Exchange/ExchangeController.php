<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Exchange;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;

final class ExchangeController
{
    private const SOURCE = 'TCMB indicative exchange rates (https://www.tcmb.gov.tr/kurlar/today.xml)';

    private ExchangeService $service;

    public function __construct(ExchangeService $service)
    {
        $this->service = $service;
    }

    public function rates(Request $request): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Istanbul'));
        $date = $request->date('date', $today);
        if ($date->format('Y') < '1996') {
            throw ApiException::badRequest('TCMB archives start in 1996.', 'date');
        }
        $bulletin = $this->bulletin($date);
        $codes = $this->codes($request->input('currencies'));

        $rates = $bulletin['rates'];
        if ($codes !== []) {
            $rates = array_values(array_filter($rates, static fn ($r) => in_array($r['code'], $codes, true)));
            $missing = array_diff($codes, array_column($rates, 'code'));
            if ($missing !== []) {
                throw ApiException::badRequest('Not in this bulletin: ' . implode(', ', $missing) . '.', 'currencies');
            }
        }

        $isPast = $bulletin['date'] < $today->format('Y-m-d');
        return Response::data(
            array_map(static fn ($r) => array_diff_key($r, ['date' => true]), $rates),
            [
                'requested_date' => $date->format('Y-m-d'),
                'bulletin_date' => $bulletin['date'],
                'bulletin_no' => $bulletin['bulletin_no'],
                'base' => 'TRY',
                'note' => 'Rates are TRY per `unit` of the currency. Weekend/holiday dates return the latest earlier bulletin.',
                'source' => self::SOURCE,
            ],
            $isPast && $date->format('Y-m-d') < $today->format('Y-m-d') ? 86400 : 600,
        );
    }

    public function currency(Request $request): Response
    {
        $code = strtoupper($request->route('code'));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Istanbul'));
        $date = $request->date('date', $today);
        $bulletin = $this->bulletin($date);
        foreach ($bulletin['rates'] as $r) {
            if ($r['code'] === $code) {
                return Response::data(array_diff_key($r, ['date' => true]), [
                    'requested_date' => $date->format('Y-m-d'),
                    'bulletin_date' => $bulletin['date'],
                    'base' => 'TRY',
                    'source' => self::SOURCE,
                ], 600);
            }
        }
        throw ApiException::notFound("TCMB does not publish a rate for $code.");
    }

    public function history(Request $request): Response
    {
        $code = strtoupper($request->route('code'));
        $tz = new \DateTimeZone('Europe/Istanbul');
        $to = $request->date('to', new \DateTimeImmutable('today', $tz));
        $from = $request->date('from', $to->modify('-30 days'));
        if ($from > $to) {
            throw ApiException::badRequest('`from` must be on or before `to`.', 'from');
        }
        if ($from->diff($to)->days > 366) {
            throw ApiException::badRequest('The range can be at most 366 days.', 'from');
        }
        $type = $request->input('type');
        if ($type !== null && !in_array($type, ExchangeService::RATE_TYPES, true)) {
            throw ApiException::badRequest('`type` must be one of: ' . implode(', ', ExchangeService::RATE_TYPES) . '.', 'type');
        }

        $rows = $this->upstream(fn () => $this->service->history($code, $from, $to));
        $series = array_map(static function ($r) use ($type) {
            if ($type !== null) {
                return ['date' => $r['date'], 'unit' => $r['unit'], $type => $r[$type]];
            }
            return array_intersect_key($r, array_flip(['date', 'unit', ...ExchangeService::RATE_TYPES]));
        }, $rows);

        return Response::data($series, [
            'code' => $code,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'count' => count($series),
            'note' => 'Only days with a published bulletin are included. Long, never-requested ranges are filled gradually; repeat the request if days are missing.',
            'source' => self::SOURCE,
        ], 3600);
    }

    public function convert(Request $request): Response
    {
        $from = strtoupper($request->required('from'));
        $to = strtoupper($request->required('to'));
        foreach (['from' => $from, 'to' => $to] as $param => $code) {
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                throw ApiException::badRequest("`$param` must be a 3-letter currency code.", $param);
            }
        }
        $amountRaw = str_replace(',', '.', $request->input('amount') ?? '1');
        if (!is_numeric($amountRaw) || (float) $amountRaw < 0 || (float) $amountRaw > 1e15) {
            throw ApiException::badRequest('`amount` must be a non-negative number.', 'amount');
        }
        $type = $request->input('type') ?? 'forex_buying';
        if (!in_array($type, ExchangeService::RATE_TYPES, true)) {
            throw ApiException::badRequest('`type` must be one of: ' . implode(', ', ExchangeService::RATE_TYPES) . '.', 'type');
        }
        $date = $request->date('date', new \DateTimeImmutable('today', new \DateTimeZone('Europe/Istanbul')));

        try {
            $result = $this->upstream(fn () => $this->service->convert((float) $amountRaw, $from, $to, $type, $date));
        } catch (\DomainException $e) {
            throw ApiException::unprocessable($e->getMessage());
        }
        return Response::data($result, ['source' => self::SOURCE], 600);
    }

    /**
     * @return array{date: string, bulletin_no: ?string, rates: list<array<string, mixed>>}
     */
    private function bulletin(\DateTimeImmutable $date): array
    {
        $bulletin = $this->upstream(fn () => $this->service->bulletinFor($date));
        if ($bulletin === null) {
            throw new ApiException(503, 'no-bulletin', 'No TCMB bulletin is available around this date yet.');
        }
        return $bulletin;
    }

    /** @return list<string> */
    private function codes(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $codes = array_values(array_unique(array_map(static fn ($c) => strtoupper(trim($c)), explode(',', $raw))));
        foreach ($codes as $c) {
            if (!preg_match('/^[A-Z]{3}$/', $c)) {
                throw ApiException::badRequest('`currencies` must be comma-separated 3-letter codes.', 'currencies');
            }
        }
        return $codes;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function upstream(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException|ApiException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw ApiException::upstream('TCMB could not be reached right now: ' . $e->getMessage());
        }
    }
}
