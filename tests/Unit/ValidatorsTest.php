<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurkiyeApi\Modules\Validation\Validators;

final class ValidatorsTest extends TestCase
{
    public function testValidTckn(): void
    {
        $r = Validators::tckn('100 000 001 46');
        self::assertTrue($r['valid']);
        self::assertSame('10000000146', $r['normalized']);
    }

    /** @return iterable<array{string}> */
    public static function invalidTckns(): iterable
    {
        yield ['10000000147']; // wrong 11th digit
        yield ['10000000156']; // wrong 10th digit
        yield ['00000000146']; // leading zero
        yield ['1000000014'];  // too short
        yield ['1000000014a'];
    }

    /** @dataProvider invalidTckns */
    public function testInvalidTckn(string $value): void
    {
        $r = Validators::tckn($value);
        self::assertFalse($r['valid']);
        self::assertNotNull($r['reason']);
    }

    public function testVkn(): void
    {
        self::assertTrue(Validators::vkn('1234567890')['valid']);
        self::assertFalse(Validators::vkn('1234567891')['valid']);
        self::assertFalse(Validators::vkn('123456789')['valid']);

        // Every generated check digit must validate, and every other digit must not.
        mt_srand(42);
        for ($i = 0; $i < 200; $i++) {
            $nine = str_pad((string) mt_rand(0, 999_999_999), 9, '0', STR_PAD_LEFT);
            $check = Validators::vknCheckDigit($nine);
            self::assertTrue(Validators::vkn($nine . $check)['valid']);
            self::assertFalse(Validators::vkn($nine . (($check + 1) % 10))['valid']);
        }
    }

    public function testIban(): void
    {
        $r = Validators::iban('tr33 0006 1005 1978 6457 8413 26');
        self::assertTrue($r['valid']);
        self::assertSame('TR330006100519786457841326', $r['normalized']);
        self::assertSame('TR33 0006 1005 1978 6457 8413 26', $r['formatted']);
        self::assertSame('00061', $r['bank_code']);

        self::assertTrue(Validators::iban('DE89 3704 0044 0532 0130 00')['valid']);
        self::assertTrue(Validators::iban('GB82WEST12345698765432')['valid']);
        self::assertFalse(Validators::iban('TR330006100519786457841327')['valid']); // checksum
        self::assertFalse(Validators::iban('TR33000610051978645784132')['valid']);  // length
        self::assertFalse(Validators::iban('XX330006100519786457841326')['valid']); // country
    }

    public function testIbanBankLookup(): void
    {
        // Construct a valid IBAN for bank code 00064 (İş Bankası).
        $bban = '0006400000112345678901';
        $check = 98 - (int) bcmod_fallback($bban . '292700');
        $iban = 'TR' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $bban;
        $r = Validators::iban($iban);
        self::assertTrue($r['valid'], (string) $r['reason']);
        self::assertSame('Türkiye İş Bankası', $r['bank']);
    }

    /** @return iterable<array{string, string, string}> */
    public static function phones(): iterable
    {
        yield ['+90 532 123 45 67', 'mobile', '+905321234567'];
        yield ['0532 123 4567', 'mobile', '+905321234567'];
        yield ['(0216) 555-12-34', 'landline', '+902165551234'];
        yield ['0090 212 555 1234', 'landline', '+902125551234'];
        yield ['0850 222 00 00', 'nationwide', '+908502220000'];
        yield ['0800 211 00 00', 'toll_free', '+908002110000'];
    }

    /** @dataProvider phones */
    public function testValidPhones(string $input, string $type, string $e164): void
    {
        $r = Validators::phone($input);
        self::assertTrue($r['valid'], (string) $r['reason']);
        self::assertSame($type, $r['type']);
        self::assertSame($e164, $r['e164']);
    }

    public function testShareCostAndInvalidPhones(): void
    {
        $r = Validators::phone('444 1 444');
        self::assertTrue($r['valid']);
        self::assertSame('shared_cost', $r['type']);

        self::assertFalse(Validators::phone('+44 20 7946 0958')['valid']);
        self::assertFalse(Validators::phone('0123 456 78 90')['valid']);
        self::assertFalse(Validators::phone('0532 123 45')['valid']);
    }

    public function testPlates(): void
    {
        $r = Validators::plate('34 abc 123', ['34' => 'İstanbul']);
        self::assertTrue($r['valid']);
        self::assertSame('34 ABC 123', $r['formatted']);
        self::assertSame('İstanbul', $r['city']);

        self::assertTrue(Validators::plate('06A1234')['valid']);
        self::assertTrue(Validators::plate('06 A 12345')['valid']);
        self::assertTrue(Validators::plate('35 AB 1234')['valid']);
        self::assertFalse(Validators::plate('82 ABC 123')['valid']); // no province 82
        self::assertFalse(Validators::plate('34 A 123')['valid']);   // 1 letter needs 4–5 digits
        self::assertFalse(Validators::plate('34 ABC 1234')['valid']); // 3 letters allow 2–3 digits
        self::assertFalse(Validators::plate('34 ÇAB 12')['valid']);
    }
}

/**
 * mod 97 on a long digit string without bcmath.
 */
function bcmod_fallback(string $digits): int
{
    $r = 0;
    foreach (str_split($digits, 7) as $chunk) {
        $r = (int) ($r . $chunk) % 97;
    }
    return $r;
}
