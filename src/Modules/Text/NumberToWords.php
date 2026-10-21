<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Text;

/**
 * Turkish number-to-words ("1.250,50" → "bin iki yüz elli lira elli kuruş").
 * Works on decimal strings, so amounts never pass through floats.
 */
final class NumberToWords
{
    private const ONES = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];
    private const TENS = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];
    private const SCALES = ['', 'bin', 'milyon', 'milyar', 'trilyon', 'katrilyon', 'kentilyon'];

    /** @var array<string, array{major: string, minor: string}> */
    public const CURRENCIES = [
        'TRY' => ['major' => 'Türk lirası', 'minor' => 'kuruş'],
        'USD' => ['major' => 'ABD doları', 'minor' => 'sent'],
        'EUR' => ['major' => 'avro', 'minor' => 'sent'],
        'GBP' => ['major' => 'İngiliz sterlini', 'minor' => 'peni'],
    ];

    /**
     * Parses "1250.50", "1.250,50", "1250,5" or "-42" into sign, integer and fraction digits.
     *
     * @return array{negative: bool, integer: string, fraction: string}
     */
    public static function parse(string $input): array
    {
        $s = str_replace([' ', "\u{00A0}", '_'], '', trim($input));
        if ($s === '' || !preg_match('/^[+-]?[0-9.,]+$/', $s)) {
            throw new \InvalidArgumentException('Not a number.');
        }
        $negative = $s[0] === '-';
        $s = ltrim($s, '+-');

        if (str_contains($s, ',')) {
            // Turkish format: dots group thousands, comma marks decimals.
            if (substr_count($s, ',') > 1) {
                throw new \InvalidArgumentException('Too many decimal separators.');
            }
            [$int, $frac] = explode(',', $s);
            if (str_contains($int, '.') && !preg_match('/^\d{1,3}(\.\d{3})+$/', $int)) {
                throw new \InvalidArgumentException('Invalid thousands grouping.');
            }
            $int = str_replace('.', '', $int);
        } elseif (substr_count($s, '.') > 1) {
            if (!preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
                throw new \InvalidArgumentException('Invalid number.');
            }
            [$int, $frac] = [str_replace('.', '', $s), ''];
        } else {
            [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        }
        if (!preg_match('/^\d*$/', $int) || !preg_match('/^\d*$/', $frac) || ($int === '' && $frac === '')) {
            throw new \InvalidArgumentException('Invalid number.');
        }
        $int = ltrim($int, '0');
        if (strlen($int) > 21) {
            throw new \InvalidArgumentException('Number is too large (max 21 integer digits).');
        }
        return ['negative' => $negative, 'integer' => $int === '' ? '0' : $int, 'fraction' => $frac];
    }

    /**
     * Words for a non-negative integer given as a digit string.
     */
    public static function integer(string $digits): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 'sıfır';
        }
        $groups = str_split(str_pad($digits, (int) ceil(strlen($digits) / 3) * 3, '0', STR_PAD_LEFT), 3);
        $count = count($groups);
        $words = [];
        foreach ($groups as $i => $group) {
            $value = (int) $group;
            if ($value === 0) {
                continue;
            }
            $scale = $count - $i - 1;
            // "bir bin" is never said; it is just "bin".
            if (!($scale === 1 && $value === 1)) {
                $words[] = self::belowThousand($value);
            }
            if ($scale > 0) {
                $words[] = self::SCALES[$scale];
            }
        }
        return implode(' ', $words);
    }

    private static function belowThousand(int $n): string
    {
        $parts = [];
        $hundreds = intdiv($n, 100);
        if ($hundreds > 0) {
            $parts[] = $hundreds === 1 ? 'yüz' : self::ONES[$hundreds] . ' yüz';
        }
        $rest = $n % 100;
        if ($rest >= 10) {
            $parts[] = self::TENS[intdiv($rest, 10)];
        }
        if ($rest % 10 > 0) {
            $parts[] = self::ONES[$rest % 10];
        }
        return implode(' ', $parts);
    }

    /**
     * Plain reading: "3,14" → "üç virgül on dört". Leading zeros in the fraction
     * are read individually ("1,05" → "bir virgül sıfır beş").
     */
    public static function plain(string $input): string
    {
        $n = self::parse($input);
        $words = self::integer($n['integer']);
        $frac = rtrim($n['fraction'], '0');
        if ($frac !== '') {
            $zeros = strlen($frac) - strlen(ltrim($frac, '0'));
            $fracWords = $zeros > 0 ? array_fill(0, $zeros, 'sıfır') : [];
            $fracWords[] = self::integer(ltrim($frac, '0'));
            $words .= ' virgül ' . implode(' ', $fracWords);
        }
        return ($n['negative'] && $words !== 'sıfır' ? 'eksi ' : '') . $words;
    }

    /**
     * Money reading, rounded half-up to 2 decimals:
     * "1250.5", TRY → "bin iki yüz elli Türk lirası elli kuruş".
     *
     * @return array{words: string, integer: string, minor: int}
     */
    public static function money(string $input, string $currency = 'TRY'): array
    {
        $currency = strtoupper($currency);
        if (!isset(self::CURRENCIES[$currency])) {
            throw new \InvalidArgumentException('Unsupported currency.');
        }
        $n = self::parse($input);
        $integer = $n['integer'];
        $frac = str_pad(substr($n['fraction'], 0, 3), 3, '0');
        $minor = intdiv((int) $frac + 5, 10);
        if ($minor === 100) {
            $minor = 0;
            $integer = self::incrementDigits($integer);
        }
        $names = self::CURRENCIES[$currency];
        $parts = [];
        if ($integer !== '0' || $minor === 0) {
            $parts[] = self::integer($integer) . ' ' . $names['major'];
        }
        if ($minor > 0) {
            $parts[] = self::integer((string) $minor) . ' ' . $names['minor'];
        }
        $words = implode(' ', $parts);
        $isZero = $integer === '0' && $minor === 0;
        return [
            'words' => ($n['negative'] && !$isZero ? 'eksi ' : '') . $words,
            'integer' => $integer,
            'minor' => $minor,
        ];
    }

    /**
     * Invoice style used on Turkish invoices: "#BinİkiYüzElliTürkLirasıElliKuruş#".
     */
    public static function compact(string $words): string
    {
        $out = '';
        foreach (explode(' ', $words) as $word) {
            $out .= TurkishText::upper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
        }
        return $out;
    }

    private static function incrementDigits(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);
                return $digits;
            }
            $digits[$i] = '0';
            $i--;
        }
        return '1' . $digits;
    }
}
