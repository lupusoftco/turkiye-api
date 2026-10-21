<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Holidays;

/**
 * Business-day arithmetic on top of the holiday calendar.
 */
final class BusinessDays
{
    private HolidayCalendar $calendar;

    /** @var list<int> */
    private array $weekend;

    private bool $halfDayIsBusinessDay;

    /**
     * @param list<int> $weekend ISO weekday numbers treated as non-working (6 = Saturday, 7 = Sunday).
     * @param bool $halfDayIsBusinessDay Whether half-day holidays (arife, 28 October) count as working days.
     */
    public function __construct(HolidayCalendar $calendar, array $weekend = [6, 7], bool $halfDayIsBusinessDay = true)
    {
        $this->calendar = $calendar;
        $this->weekend = $weekend;
        $this->halfDayIsBusinessDay = $halfDayIsBusinessDay;
    }

    /**
     * @return array{date: string, weekday: string, is_weekend: bool, is_holiday: bool, is_half_day: bool, is_business_day: bool, holidays: list<array<string, mixed>>}
     */
    public function describe(\DateTimeImmutable $date): array
    {
        $holidays = $this->calendar->on($date);
        $fullHoliday = false;
        $halfDay = false;
        foreach ($holidays as $h) {
            if ($h['half_day']) {
                $halfDay = true;
            } else {
                $fullHoliday = true;
            }
        }
        $isWeekend = in_array((int) $date->format('N'), $this->weekend, true);
        $isHalfDayOnly = $halfDay && !$fullHoliday;

        return [
            'date' => $date->format('Y-m-d'),
            'weekday' => self::weekdayTr((int) $date->format('N')),
            'is_weekend' => $isWeekend,
            'is_holiday' => $fullHoliday,
            'is_half_day' => $isHalfDayOnly,
            'is_business_day' => !$isWeekend && !$fullHoliday && (!$isHalfDayOnly || $this->halfDayIsBusinessDay),
            'holidays' => $holidays,
        ];
    }

    public function isBusinessDay(\DateTimeImmutable $date): bool
    {
        return $this->describe($date)['is_business_day'];
    }

    /**
     * Number of business days in the inclusive range [$from, $to].
     */
    public function count(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return -$this->count($to, $from);
        }
        $count = 0;
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            if ($this->isBusinessDay($d)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The date $days business days after $date (before it when negative).
     * The start date itself is not counted.
     */
    public function add(\DateTimeImmutable $date, int $days): \DateTimeImmutable
    {
        $step = $days >= 0 ? '+1 day' : '-1 day';
        $remaining = abs($days);
        $d = $date;
        while ($remaining > 0) {
            $d = $d->modify($step);
            if ($this->isBusinessDay($d)) {
                $remaining--;
            }
        }
        return $d;
    }

    /**
     * The first business day strictly after $date.
     */
    public function next(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $this->add($date, 1);
    }

    public static function weekdayTr(int $iso): string
    {
        return ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'][$iso];
    }
}
