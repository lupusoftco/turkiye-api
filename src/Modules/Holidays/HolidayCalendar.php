<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Holidays;

/**
 * Official public holidays in Türkiye (Law No. 2429).
 *
 * Religious holidays follow the Hijri calendar. First days of every bayram
 * are listed in BAYRAM_STARTS, precomputed from the Umm al-Qura calendar
 * (ICU) so no `intl` extension is needed at runtime. Umm al-Qura matches the
 * dates announced by the Presidency of Religious Affairs (Diyanet) in every
 * year listed in VERIFIED; other years are flagged `estimated`.
 */
final class HolidayCalendar
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2070;

    /**
     * First day of Ramazan Bayramı and Kurban Bayramı as published by Diyanet.
     *
     * @var array<int, array{ramazan: string, kurban: string}>
     */
    public const VERIFIED = [
        2020 => ['ramazan' => '2020-05-24', 'kurban' => '2020-07-31'],
        2021 => ['ramazan' => '2021-05-13', 'kurban' => '2021-07-20'],
        2022 => ['ramazan' => '2022-05-02', 'kurban' => '2022-07-09'],
        2023 => ['ramazan' => '2023-04-21', 'kurban' => '2023-06-28'],
        2024 => ['ramazan' => '2024-04-10', 'kurban' => '2024-06-16'],
        2025 => ['ramazan' => '2025-03-30', 'kurban' => '2025-06-06'],
        2026 => ['ramazan' => '2026-03-20', 'kurban' => '2026-05-27'],
        2027 => ['ramazan' => '2027-03-09', 'kurban' => '2027-05-16'],
    ];

    /**
     * First days of each bayram starting in a Gregorian year (R = Ramazan,
     * K = Kurban), from the Umm al-Qura calendar. Regenerate with
     * `php tools/hijri-table.php` (needs ext-intl).
     *
     * @var array<int, string>
     */
    public const BAYRAM_STARTS = [
        1999 => '1999-01-19R 1999-03-28K',
        2000 => '2000-01-08R 2000-03-16K 2000-12-27R',
        2001 => '2001-03-05K 2001-12-16R',
        2002 => '2002-02-22K 2002-12-05R',
        2003 => '2003-02-11K 2003-11-25R',
        2004 => '2004-02-01K 2004-11-14R',
        2005 => '2005-01-21K 2005-11-03R',
        2006 => '2006-01-10K 2006-10-23R 2006-12-31K',
        2007 => '2007-10-13R 2007-12-20K',
        2008 => '2008-10-01R 2008-12-08K',
        2009 => '2009-09-20R 2009-11-27K',
        2010 => '2010-09-10R 2010-11-16K',
        2011 => '2011-08-30R 2011-11-06K',
        2012 => '2012-08-19R 2012-10-26K',
        2013 => '2013-08-08R 2013-10-15K',
        2014 => '2014-07-28R 2014-10-04K',
        2015 => '2015-07-17R 2015-09-23K',
        2016 => '2016-07-06R 2016-09-11K',
        2017 => '2017-06-25R 2017-09-01K',
        2018 => '2018-06-15R 2018-08-21K',
        2019 => '2019-06-04R 2019-08-11K',
        2020 => '2020-05-24R 2020-07-31K',
        2021 => '2021-05-13R 2021-07-20K',
        2022 => '2022-05-02R 2022-07-09K',
        2023 => '2023-04-21R 2023-06-28K',
        2024 => '2024-04-10R 2024-06-16K',
        2025 => '2025-03-30R 2025-06-06K',
        2026 => '2026-03-20R 2026-05-27K',
        2027 => '2027-03-09R 2027-05-16K',
        2028 => '2028-02-26R 2028-05-05K',
        2029 => '2029-02-14R 2029-04-24K',
        2030 => '2030-02-04R 2030-04-13K',
        2031 => '2031-01-24R 2031-04-02K',
        2032 => '2032-01-14R 2032-03-22K',
        2033 => '2033-01-03R 2033-03-12K 2033-12-23R',
        2034 => '2034-03-01K 2034-12-12R',
        2035 => '2035-02-19K 2035-12-01R',
        2036 => '2036-02-08K 2036-11-19R',
        2037 => '2037-01-27K 2037-11-09R',
        2038 => '2038-01-16K 2038-10-29R',
        2039 => '2039-01-05K 2039-10-19R 2039-12-26K',
        2040 => '2040-10-07R 2040-12-15K',
        2041 => '2041-09-27R 2041-12-04K',
        2042 => '2042-09-16R 2042-11-23K',
        2043 => '2043-09-05R 2043-11-12K',
        2044 => '2044-08-24R 2044-11-01K',
        2045 => '2045-08-14R 2045-10-21K',
        2046 => '2046-08-04R 2046-10-11K',
        2047 => '2047-07-24R 2047-09-30K',
        2048 => '2048-07-13R 2048-09-19K',
        2049 => '2049-07-02R 2049-09-08K',
        2050 => '2050-06-21R 2050-08-28K',
        2051 => '2051-06-10R 2051-08-17K',
        2052 => '2052-05-30R 2052-08-06K',
        2053 => '2053-05-19R 2053-07-26K',
        2054 => '2054-05-09R 2054-07-16K',
        2055 => '2055-04-28R 2055-07-05K',
        2056 => '2056-04-16R 2056-06-24K',
        2057 => '2057-04-05R 2057-06-13K',
        2058 => '2058-03-26R 2058-06-02K',
        2059 => '2059-03-15R 2059-05-22K',
        2060 => '2060-03-04R 2060-05-11K',
        2061 => '2061-02-22R 2061-04-30K',
        2062 => '2062-02-11R 2062-04-20K',
        2063 => '2063-01-31R 2063-04-09K',
        2064 => '2064-01-20R 2064-03-28K',
        2065 => '2065-01-08R 2065-03-17K 2065-12-29R',
        2066 => '2066-03-07K 2066-12-18R',
        2067 => '2067-02-24K 2067-12-08R',
        2068 => '2068-02-14K 2068-11-26R',
        2069 => '2069-02-02K 2069-11-15R',
        2070 => '2070-01-23K 2070-11-04R',
        2071 => '2071-01-12K 2071-10-24R',
    ];

    /** @var array<int, list<array<string, mixed>>> */
    private array $cache = [];

    /**
     * @return list<array{date: string, name: string, name_en: string, type: string, half_day: bool, starts_at: ?string, estimated: bool}>
     */
    public function forYear(int $year): array
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new \OutOfRangeException(sprintf('Supported years are %d–%d.', self::MIN_YEAR, self::MAX_YEAR));
        }
        if (isset($this->cache[$year])) {
            /** @var list<array{date: string, name: string, name_en: string, type: string, half_day: bool, starts_at: ?string, estimated: bool}> */
            return $this->cache[$year];
        }

        $list = [];
        $national = static function (string $md, string $tr, string $en, bool $half = false) use (&$list, $year): void {
            $list[] = self::entry("$year-$md", $tr, $en, 'national', $half, false);
        };

        $national('01-01', 'Yılbaşı', "New Year's Day");
        $national('04-23', 'Ulusal Egemenlik ve Çocuk Bayramı', 'National Sovereignty and Children\'s Day');
        if ($year >= 2009) {
            $national('05-01', 'Emek ve Dayanışma Günü', 'Labour and Solidarity Day');
        }
        $national('05-19', 'Atatürk\'ü Anma, Gençlik ve Spor Bayramı', 'Commemoration of Atatürk, Youth and Sports Day');
        if ($year >= 2017) {
            $national('07-15', 'Demokrasi ve Millî Birlik Günü', 'Democracy and National Unity Day');
        }
        $national('08-30', 'Zafer Bayramı', 'Victory Day');
        $national('10-28', 'Cumhuriyet Bayramı Arifesi', 'Republic Day Eve', true);
        $national('10-29', 'Cumhuriyet Bayramı', 'Republic Day');

        // Bayrams near a year boundary can spill over (Kurban started 31 Dec 2006),
        // so neighbouring years are expanded too and filtered by date below.
        $seen = [];
        foreach ([$year - 1, $year, $year + 1] as $y) {
            foreach ($this->religiousStarts($y) as [$kind, $start, $estimated]) {
                $key = $kind . $start->format('Y-m-d');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $days = $kind === 'ramazan' ? 3 : 4;
                $nameTr = $kind === 'ramazan' ? 'Ramazan Bayramı' : 'Kurban Bayramı';
                $nameEn = $kind === 'ramazan' ? 'Eid al-Fitr' : 'Eid al-Adha';
                $list[] = self::entry($start->modify('-1 day')->format('Y-m-d'), "$nameTr Arifesi", "$nameEn Eve", 'religious', true, $estimated);
                for ($i = 0; $i < $days; $i++) {
                    $list[] = self::entry(
                        $start->modify("+$i day")->format('Y-m-d'),
                        sprintf('%s %d. Gün', $nameTr, $i + 1),
                        sprintf('%s Day %d', $nameEn, $i + 1),
                        'religious',
                        false,
                        $estimated,
                    );
                }
            }
        }
        $list = array_values(array_filter($list, static fn ($h) => str_starts_with($h['date'], "$year-")));

        usort($list, static fn ($a, $b) => [$a['date'], $a['half_day'] ? 0 : 1] <=> [$b['date'], $b['half_day'] ? 0 : 1]);
        return $this->cache[$year] = $list;
    }

    /**
     * Holidays on a given date (several can overlap, e.g. 19 May 2027).
     *
     * @return list<array{date: string, name: string, name_en: string, type: string, half_day: bool, starts_at: ?string, estimated: bool}>
     */
    public function on(\DateTimeImmutable $date): array
    {
        $key = $date->format('Y-m-d');
        return array_values(array_filter(
            $this->forYear((int) $date->format('Y')),
            static fn ($h) => $h['date'] === $key,
        ));
    }

    /**
     * First days of every Ramazan and Kurban Bayramı that start in $year.
     *
     * @return list<array{0: string, 1: \DateTimeImmutable, 2: bool}> [kind, first day, estimated]
     */
    private function religiousStarts(int $year): array
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        $result = [];
        foreach (explode(' ', self::BAYRAM_STARTS[$year] ?? '') as $item) {
            if ($item === '') {
                continue;
            }
            $kind = substr($item, -1) === 'R' ? 'ramazan' : 'kurban';
            $date = substr($item, 0, 10);
            $verified = isset(self::VERIFIED[$year]) && self::VERIFIED[$year][$kind] === $date;
            $result[] = [$kind, new \DateTimeImmutable($date, $tz), !$verified];
        }
        return $result;
    }

    /**
     * Gregorian dates in $year whose Umm al-Qura date is $month/$day.
     * A Hijri year is ~11 days shorter, so a date occurs once or twice per year.
     * Used to (re)generate BAYRAM_STARTS; requires ext-intl.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function hijriToGregorian(int $year, int $month, int $day, \DateTimeZone $tz): array
    {
        $cal = \IntlCalendar::createInstance('UTC', 'tr_TR@calendar=islamic-umalqura');
        $found = [];
        $cursor = new \DateTimeImmutable("$year-01-01 12:00:00", new \DateTimeZone('UTC'));
        while ((int) $cursor->format('Y') === $year) {
            $cal->setTime((float) ($cursor->getTimestamp() * 1000));
            if ($cal->get(\IntlCalendar::FIELD_MONTH) + 1 === $month && $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH) === $day) {
                $found[] = new \DateTimeImmutable($cursor->format('Y-m-d'), $tz);
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $found;
    }

    /**
     * @return array{date: string, name: string, name_en: string, type: string, half_day: bool, starts_at: ?string, estimated: bool}
     */
    private static function entry(string $date, string $name, string $nameEn, string $type, bool $halfDay, bool $estimated): array
    {
        return [
            'date' => $date,
            'name' => $name,
            'name_en' => $nameEn,
            'type' => $type,
            'half_day' => $halfDay,
            // Half-day holidays begin at 13:00 under Law No. 2429.
            'starts_at' => $halfDay ? '13:00' : null,
            'estimated' => $estimated,
        ];
    }
}
