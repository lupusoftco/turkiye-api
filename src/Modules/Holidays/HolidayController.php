<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Holidays;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;

final class HolidayController
{
    private const MAX_RANGE_DAYS = 3660;

    private HolidayCalendar $calendar;

    public function __construct(HolidayCalendar $calendar)
    {
        $this->calendar = $calendar;
    }

    public function list(Request $request): Response
    {
        $year = $request->int('year', (int) date('Y'), HolidayCalendar::MIN_YEAR, HolidayCalendar::MAX_YEAR);
        $type = $request->input('type');
        if ($type !== null && !in_array($type, ['national', 'religious'], true)) {
            throw ApiException::badRequest('`type` must be national or religious.', 'type');
        }
        $includeHalf = $request->bool('half_days', true);

        $list = array_values(array_filter(
            $this->calendar->forYear($year),
            static fn ($h) => ($type === null || $h['type'] === $type) && ($includeHalf || !$h['half_day']),
        ));
        $estimated = (bool) array_filter($list, static fn ($h) => $h['estimated']);

        return Response::data($list, [
            'year' => $year,
            'count' => count($list),
            'source' => 'Law No. 2429 on National Holidays and General Holidays; religious dates per Diyanet.',
            'note' => $estimated
                ? 'Religious holiday dates for this year are calculated (Umm al-Qura) and should be confirmed against the Diyanet calendar. Administrative leave ("köprü") days are not included.'
                : 'Administrative leave ("köprü") days announced by the government are not included.',
        ], 86400);
    }

    public function check(Request $request): Response
    {
        $date = $request->date('date', new \DateTimeImmutable('today', new \DateTimeZone('Europe/Istanbul')));
        $this->assertYear($date);
        return Response::data($this->businessDays($request)->describe($date), [], 3600);
    }

    public function count(Request $request): Response
    {
        $from = $request->date('from');
        $to = $request->date('to');
        $this->assertRange($from, $to);
        $bd = $this->businessDays($request);
        $total = abs((int) $from->diff($to)->days) + 1;
        $business = abs($bd->count($from, $to));
        return Response::data([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'calendar_days' => $total,
            'business_days' => $business,
            'non_business_days' => $total - $business,
        ], ['note' => 'Both `from` and `to` are included in the count.'], 3600);
    }

    public function add(Request $request): Response
    {
        $date = $request->date('date');
        $days = $request->int('days', 0, -2500, 2500);
        $this->assertYear($date);
        $bd = $this->businessDays($request);
        $result = $bd->add($date, $days);
        $this->assertYear($result);
        return Response::data([
            'date' => $date->format('Y-m-d'),
            'days' => $days,
            'result' => $result->format('Y-m-d'),
            'result_weekday' => BusinessDays::weekdayTr((int) $result->format('N')),
        ], ['note' => 'The start date itself is not counted.'], 3600);
    }

    private function businessDays(Request $request): BusinessDays
    {
        $saturday = $request->bool('saturday_is_workday', false);
        $halfDay = $request->input('half_day') ?? 'business';
        if (!in_array($halfDay, ['business', 'off'], true)) {
            throw ApiException::badRequest('`half_day` must be "business" or "off".', 'half_day');
        }
        return new BusinessDays($this->calendar, $saturday ? [7] : [6, 7], $halfDay === 'business');
    }

    private function assertYear(\DateTimeImmutable $date): void
    {
        $y = (int) $date->format('Y');
        if ($y < HolidayCalendar::MIN_YEAR || $y > HolidayCalendar::MAX_YEAR) {
            throw ApiException::badRequest(sprintf('Dates must be between %d and %d.', HolidayCalendar::MIN_YEAR, HolidayCalendar::MAX_YEAR), 'date');
        }
    }

    private function assertRange(\DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $this->assertYear($from);
        $this->assertYear($to);
        if ($from->diff($to)->days > self::MAX_RANGE_DAYS) {
            throw ApiException::badRequest('The range can be at most 10 years.', 'to');
        }
    }
}
