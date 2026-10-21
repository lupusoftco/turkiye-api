<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurkiyeApi\Modules\Holidays\BusinessDays;
use TurkiyeApi\Modules\Holidays\HolidayCalendar;

final class HolidaysTest extends TestCase
{
    private static function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Europe/Istanbul'));
    }

    public function testCalculationMatchesEveryDiyanetVerifiedYear(): void
    {
        if (!class_exists(\IntlCalendar::class)) {
            self::markTestSkipped('ext-intl is needed for the calendar calculation.');
        }
        $tz = new \DateTimeZone('Europe/Istanbul');
        $verified = (new \ReflectionClass(HolidayCalendar::class))->getConstant('VERIFIED');
        self::assertIsArray($verified);
        foreach ($verified as $year => $dates) {
            self::assertSame($dates['ramazan'], HolidayCalendar::hijriToGregorian($year, 10, 1, $tz)[0]->format('Y-m-d'), "Ramazan $year");
            self::assertSame($dates['kurban'], HolidayCalendar::hijriToGregorian($year, 12, 10, $tz)[0]->format('Y-m-d'), "Kurban $year");
        }
    }

    public function testBundledTableMatchesIcuCalendar(): void
    {
        if (!class_exists(\IntlCalendar::class)) {
            self::markTestSkipped('ext-intl is needed to recompute the table.');
        }
        $tz = new \DateTimeZone('Europe/Istanbul');
        foreach (HolidayCalendar::BAYRAM_STARTS as $year => $line) {
            $expected = [];
            foreach (['R' => [10, 1], 'K' => [12, 10]] as $suffix => [$m, $d]) {
                foreach (HolidayCalendar::hijriToGregorian($year, $m, $d, $tz) as $date) {
                    $expected[] = $date->format('Y-m-d') . $suffix;
                }
            }
            sort($expected);
            self::assertSame(implode(' ', $expected), $line, "Year $year");
        }
    }

    public function testYear2026(): void
    {
        $list = (new HolidayCalendar())->forYear(2026);
        $byDate = [];
        foreach ($list as $h) {
            $byDate[$h['date']][] = $h['name'];
        }
        self::assertSame(['Cumhuriyet Bayramı Arifesi'], $byDate['2026-10-28']);
        self::assertSame(['Ramazan Bayramı Arifesi'], $byDate['2026-03-19']);
        self::assertSame(['Ramazan Bayramı 1. Gün'], $byDate['2026-03-20']);
        self::assertSame(['Kurban Bayramı 4. Gün'], $byDate['2026-05-30']);
        self::assertArrayHasKey('2026-07-15', $byDate);
        // 7 national days + 28 Oct eve + 2 bayram eves + 3 + 4 bayram days
        self::assertCount(17, $list);
        self::assertFalse((bool) array_filter($list, static fn ($h) => $h['estimated']));
    }

    public function testOverlappingHolidaysAreBothListed(): void
    {
        $on = (new HolidayCalendar())->on(self::d('2027-05-19'));
        self::assertCount(2, $on);
        self::assertEqualsCanonicalizing(
            ["Atatürk'ü Anma, Gençlik ve Spor Bayramı", 'Kurban Bayramı 4. Gün'],
            array_column($on, 'name'),
        );
    }

    public function testBayramSpanningNewYear(): void
    {
        $cal = new HolidayCalendar();
        $dates2006 = array_column(array_filter($cal->forYear(2006), static fn ($h) => str_contains($h['name'], 'Kurban')), 'date');
        $dates2007 = array_column(array_filter($cal->forYear(2007), static fn ($h) => str_contains($h['name'], 'Kurban')), 'date');
        self::assertContains('2006-12-31', $dates2006);
        self::assertContains('2007-01-01', $dates2007);
        self::assertContains('2007-01-03', $dates2007);
        self::assertContains('2007-12-20', $dates2007); // second Kurban in the same Gregorian year
    }

    public function testHistoricalRules(): void
    {
        $names2008 = array_column((new HolidayCalendar())->forYear(2008), 'name');
        self::assertNotContains('Emek ve Dayanışma Günü', $names2008);
        self::assertNotContains('Demokrasi ve Millî Birlik Günü', $names2008);
        $names2016 = array_column((new HolidayCalendar())->forYear(2016), 'name');
        self::assertContains('Emek ve Dayanışma Günü', $names2016);
        self::assertNotContains('Demokrasi ve Millî Birlik Günü', $names2016);
    }

    public function testFutureYearsAreFlaggedEstimated(): void
    {
        $religious = array_filter((new HolidayCalendar())->forYear(2035), static fn ($h) => $h['type'] === 'religious');
        self::assertNotEmpty($religious);
        foreach ($religious as $h) {
            self::assertTrue($h['estimated']);
        }
    }

    public function testOutOfRangeYear(): void
    {
        $this->expectException(\OutOfRangeException::class);
        (new HolidayCalendar())->forYear(1999);
    }

    public function testDescribe(): void
    {
        $bd = new BusinessDays(new HolidayCalendar());
        $republic = $bd->describe(self::d('2026-10-29'));
        self::assertTrue($republic['is_holiday']);
        self::assertFalse($republic['is_business_day']);
        self::assertSame('Perşembe', $republic['weekday']);

        $eve = $bd->describe(self::d('2026-10-28'));
        self::assertTrue($eve['is_half_day']);
        self::assertTrue($eve['is_business_day']);
        self::assertFalse((new BusinessDays(new HolidayCalendar(), [6, 7], false))->isBusinessDay(self::d('2026-10-28')));

        self::assertTrue($bd->describe(self::d('2026-10-24'))['is_weekend']);
    }

    public function testCountAndAdd(): void
    {
        $bd = new BusinessDays(new HolidayCalendar());
        // 26–30 Oct 2026: Mon, Tue, Wed (half day), Thu (holiday), Fri
        self::assertSame(4, $bd->count(self::d('2026-10-26'), self::d('2026-10-30')));
        self::assertSame(-4, $bd->count(self::d('2026-10-30'), self::d('2026-10-26')));
        // Ramazan Bayramı 2026: eve Thu 19 Mar (half), Fri 20 – Sun 22 Mar
        self::assertSame('2026-03-23', $bd->add(self::d('2026-03-19'), 1)->format('Y-m-d'));
        self::assertSame('2026-03-19', $bd->add(self::d('2026-03-23'), -1)->format('Y-m-d'));
        self::assertSame('2026-10-28', $bd->add(self::d('2026-10-28'), 0)->format('Y-m-d'));
        // Saturday as a working day
        $sat = new BusinessDays(new HolidayCalendar(), [7]);
        self::assertSame('2026-10-24', $sat->next(self::d('2026-10-23'))->format('Y-m-d'));
        // 2026 has 261 weekdays; 10 full-day holidays fall on weekdays
        // (1 Jan, 20 Mar, 23 Apr, 1 May, 19 May, 27–29 May, 15 Jul, 29 Oct).
        self::assertSame(251, $bd->count(self::d('2026-01-01'), self::d('2026-12-31')));
    }
}
