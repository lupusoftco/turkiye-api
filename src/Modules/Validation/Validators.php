<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Validation;

/**
 * Algorithmic validators. None of these query government systems: a valid
 * TCKN checksum does not mean the number belongs to a real person.
 */
final class Validators
{
    /**
     * @return array{valid: bool, normalized: ?string, reason: ?string}
     */
    public static function tckn(string $input): array
    {
        $v = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{11}$/', $v)) {
            return self::fail('TCKN must be exactly 11 digits.');
        }
        if ($v[0] === '0') {
            return self::fail('TCKN cannot start with 0.');
        }
        $d = array_map('intval', str_split($v));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        $tenth = ((($odd * 7) - $even) % 10 + 10) % 10;
        if ($tenth !== $d[9]) {
            return self::fail('10th digit checksum does not match.');
        }
        if (array_sum(array_slice($d, 0, 10)) % 10 !== $d[10]) {
            return self::fail('11th digit checksum does not match.');
        }
        return ['valid' => true, 'normalized' => $v, 'reason' => null];
    }

    /**
     * Vergi Kimlik Numarası (10 digits) checksum used by the Revenue Administration.
     *
     * @return array{valid: bool, normalized: ?string, reason: ?string}
     */
    public static function vkn(string $input): array
    {
        $v = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{10}$/', $v)) {
            return self::fail('VKN must be exactly 10 digits.');
        }
        return self::vknCheckDigit(substr($v, 0, 9)) === (int) $v[9]
            ? ['valid' => true, 'normalized' => $v, 'reason' => null]
            : self::fail('Check digit does not match.');
    }

    public static function vknCheckDigit(string $firstNine): int
    {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $tmp = ((int) $firstNine[$i] + (9 - $i)) % 10;
            $value = ($tmp * (2 ** (9 - $i))) % 9;
            if ($tmp !== 0 && $value === 0) {
                $value = 9;
            }
            $sum += $value;
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * @return array{valid: bool, normalized: ?string, reason: ?string, country: ?string, formatted: ?string, bank_code: ?string, bank: ?string}
     */
    public static function iban(string $input): array
    {
        $empty = ['country' => null, 'formatted' => null, 'bank_code' => null, 'bank' => null];
        $v = strtoupper(preg_replace('/[\s\-]+/', '', $input) ?? '');
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $v)) {
            return self::fail('IBAN must start with a 2-letter country code and 2 check digits.') + $empty;
        }
        $country = (string) substr($v, 0, 2);
        $expected = IbanData::LENGTHS[$country] ?? null;
        if ($expected === null) {
            return self::fail("Country code $country does not use IBAN or is unknown.") + $empty;
        }
        if (strlen($v) !== $expected) {
            return self::fail("$country IBANs are $expected characters long; got " . strlen($v) . '.') + $empty;
        }
        if ($country === 'TR' && !preg_match('/^TR\d{24}$/', $v)) {
            return self::fail('Turkish IBANs contain only digits after "TR".') + $empty;
        }
        if (self::mod97(substr($v, 4) . $country . substr($v, 2, 2)) !== 1) {
            return self::fail('Checksum (mod 97) does not match.') + $empty;
        }
        $bankCode = $country === 'TR' ? (string) substr($v, 4, 5) : null;
        return [
            'valid' => true,
            'normalized' => $v,
            'reason' => null,
            'country' => $country,
            'formatted' => trim(chunk_split($v, 4, ' ')),
            'bank_code' => $bankCode,
            'bank' => $bankCode !== null ? (IbanData::TR_BANKS[$bankCode] ?? null) : null,
        ];
    }

    private static function mod97(string $s): int
    {
        $numeric = '';
        foreach (str_split($s) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }
        return $remainder;
    }

    /**
     * Turkish phone numbers.
     *
     * @return array{valid: bool, normalized: ?string, reason: ?string, type: ?string, e164: ?string, national: ?string, area_code: ?string}
     */
    public static function phone(string $input): array
    {
        $empty = ['type' => null, 'e164' => null, 'national' => null, 'area_code' => null];
        $raw = trim($input);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($raw, '+') && !str_starts_with($digits, '90')) {
            return self::fail('Only Turkish (+90) numbers are supported.') + $empty;
        }
        if (str_starts_with($digits, '0090')) {
            $digits = (string) substr($digits, 4);
        } elseif (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = (string) substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = (string) substr($digits, 1);
        }

        // 444 X XXX style nationwide numbers have 7 digits and no prefix.
        if (preg_match('/^444\d{4}$/', $digits)) {
            return [
                'valid' => true, 'normalized' => $digits, 'reason' => null, 'type' => 'shared_cost',
                'e164' => null, 'national' => '444 ' . substr($digits, 3, 2) . ' ' . substr($digits, 5, 2), 'area_code' => null,
            ];
        }
        if (strlen($digits) !== 10) {
            return self::fail('Turkish numbers have 10 digits after the 0 or +90 prefix.') + $empty;
        }
        $prefix = (string) substr($digits, 0, 3);
        $special = ['800' => 'toll_free', '850' => 'nationwide', '822' => 'satellite', '900' => 'premium'];
        if (preg_match('/^5[0345]\d$/', $prefix)) {
            $type = 'mobile';
        } elseif (isset($special[$prefix])) {
            $type = $special[$prefix];
        } elseif (in_array($prefix, IbanData::AREA_CODES, true)) {
            $type = 'landline';
        } else {
            $type = null;
        }
        if ($type === null) {
            return self::fail("Prefix $prefix is not a known Turkish mobile, area or service code.") + $empty;
        }
        return [
            'valid' => true,
            'normalized' => $digits,
            'reason' => null,
            'type' => $type,
            'e164' => '+90' . $digits,
            'national' => sprintf('0%s %s %s %s', $prefix, substr($digits, 3, 3), substr($digits, 6, 2), substr($digits, 8, 2)),
            'area_code' => $type === 'landline' ? $prefix : null,
        ];
    }

    /**
     * Turkish vehicle plates: 1 letter + 4–5 digits, 2 letters + 3–4 digits,
     * or 3 letters + 2–3 digits, after a 01–81 province code.
     *
     * @param array<int|string, string> $cities code => name
     * @return array{valid: bool, normalized: ?string, reason: ?string, formatted: ?string, city_code: ?string, city: ?string}
     */
    public static function plate(string $input, array $cities = []): array
    {
        $empty = ['formatted' => null, 'city_code' => null, 'city' => null];
        $v = strtoupper(preg_replace('/[\s\-]+/', '', $input) ?? '');
        if (!preg_match('/^(\d{2})([A-Z]{1,3})(\d{2,5})$/', $v, $m)) {
            return self::fail('Plates look like "34 ABC 123": province code, 1–3 Latin letters, 2–5 digits.') + $empty;
        }
        [, $code, $letters, $numbers] = $m;
        if ((int) $code < 1 || (int) $code > 81) {
            return self::fail('Province code must be between 01 and 81.') + $empty;
        }
        $digitRange = [1 => [4, 5], 2 => [3, 4], 3 => [2, 3]][strlen($letters)];
        if (strlen($numbers) < $digitRange[0] || strlen($numbers) > $digitRange[1]) {
            return self::fail(sprintf(
                'With %d letter(s) the number part must have %d–%d digits.',
                strlen($letters),
                $digitRange[0],
                $digitRange[1],
            )) + $empty;
        }
        return [
            'valid' => true,
            'normalized' => $v,
            'reason' => null,
            'formatted' => "$code $letters $numbers",
            'city_code' => $code,
            'city' => $cities[$code] ?? null,
        ];
    }

    /**
     * @return array{valid: false, normalized: null, reason: string}
     */
    private static function fail(string $reason): array
    {
        return ['valid' => false, 'normalized' => null, 'reason' => $reason];
    }
}
