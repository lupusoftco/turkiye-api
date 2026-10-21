<?php

declare(strict_types=1);

namespace TurkiyeApi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TurkiyeApi\Modules\Text\NumberToWords;
use TurkiyeApi\Modules\Text\Syllabifier;
use TurkiyeApi\Modules\Text\TurkishText;

final class TextTest extends TestCase
{
    public function testUpperAndLowerUseTurkishDottedAndDotlessI(): void
    {
        self::assertSame('İSTANBUL IĞDIR ÇİĞ', TurkishText::upper('istanbul ığdır çiğ'));
        self::assertSame('istanbul ığdır çiğ', TurkishText::lower('İSTANBUL IĞDIR ÇİĞ'));
        self::assertSame('istanbul', TurkishText::lower("I\u{0307}STANBUL"));
    }

    public function testTitleAndSentenceCase(): void
    {
        self::assertSame("İstanbul'da Ilık Bir Gün", TurkishText::title("İSTANBUL'DA ılık BİR gün"));
        self::assertSame('Iğdır-Kars Yolu', TurkishText::title('IĞDIR-KARS YOLU'));
        self::assertSame('Merhaba dünya. İyi günler!', TurkishText::sentence('MERHABA DÜNYA. İYİ GÜNLER!'));
    }

    public function testSlug(): void
    {
        self::assertSame('istanbul-da-ilik-bir-gun', TurkishText::slug("İSTANBUL'DA ılık bir gün!"));
        self::assertSame('cagri_sogut', TurkishText::slug('  Çağrı   Söğüt ', '_'));
        self::assertSame('kahve-ve-kod-2026', TurkishText::slug('Kahve & Kod — 2026'));
        self::assertSame('', TurkishText::slug('!!!'));
    }

    public function testSearchKeyIgnoresCaseAndTurkishCharacters(): void
    {
        self::assertSame(TurkishText::searchKey('ŞİŞLİ'), TurkishText::searchKey('sisli'));
        self::assertSame('cihangir mah', TurkishText::searchKey('Cihangir   Mah'));
    }

    /** @return iterable<array{string, string}> */
    public static function plainNumbers(): iterable
    {
        yield ['0', 'sıfır'];
        yield ['7', 'yedi'];
        yield ['10', 'on'];
        yield ['19', 'on dokuz'];
        yield ['100', 'yüz'];
        yield ['101', 'yüz bir'];
        yield ['999', 'dokuz yüz doksan dokuz'];
        yield ['1000', 'bin'];
        yield ['1001', 'bin bir'];
        yield ['2000', 'iki bin'];
        yield ['11000', 'on bir bin'];
        yield ['100000', 'yüz bin'];
        yield ['101000', 'yüz bir bin'];
        yield ['1000000', 'bir milyon'];
        yield ['1.250.000', 'bir milyon iki yüz elli bin'];
        yield ['2000000000', 'iki milyar'];
        yield ['-42', 'eksi kırk iki'];
        yield ['3,14', 'üç virgül on dört'];
        yield ['1,05', 'bir virgül sıfır beş'];
        yield ['12.5', 'on iki virgül beş'];
        yield ['-0', 'sıfır'];
    }

    /** @dataProvider plainNumbers */
    public function testPlainNumberToWords(string $input, string $expected): void
    {
        self::assertSame($expected, NumberToWords::plain($input));
    }

    public function testMoneyToWords(): void
    {
        self::assertSame('bin iki yüz elli Türk lirası elli kuruş', NumberToWords::money('1.250,50')['words']);
        self::assertSame('bin iki yüz elli Türk lirası elli kuruş', NumberToWords::money('1250.5')['words']);
        self::assertSame('kırk beş kuruş', NumberToWords::money('0,45')['words']);
        self::assertSame('sıfır Türk lirası', NumberToWords::money('0')['words']);
        self::assertSame('yüz ABD doları', NumberToWords::money('99.995', 'USD')['words']);
        self::assertSame('on avro bir sent', NumberToWords::money('10.005', 'EUR')['words']);
        self::assertSame('bin Türk lirası', NumberToWords::money('999,999')['words']);
    }

    public function testInvoiceCompactForm(): void
    {
        $words = NumberToWords::money('1250.50')['words'];
        self::assertSame('BinİkiYüzElliTürkLirasıElliKuruş', NumberToWords::compact($words));
    }

    /** @return iterable<array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield ['abc'];
        yield ['1,2,3'];
        yield ['12.34.5'];
        yield [''];
        yield ['1e5'];
        yield ['1234567890123456789012'];
    }

    /** @dataProvider invalidNumbers */
    public function testInvalidNumbersAreRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NumberToWords::plain($input);
    }

    /** @return iterable<array{string, list<string>}> */
    public static function syllableWords(): iterable
    {
        yield ['kardeş', ['kar', 'deş']];
        yield ['İstanbul', ['İs', 'tan', 'bul']];
        yield ['Türkiye', ['Tür', 'ki', 'ye']];
        yield ['saat', ['sa', 'at']];
        yield ['kontrol', ['kont', 'rol']];
        yield ['okul', ['o', 'kul']];
        yield ['ev', ['ev']];
        yield ['bilgisayar', ['bil', 'gi', 'sa', 'yar']];
        yield ['Iğdır', ['Iğ', 'dır']];
    }

    /**
     * @param list<string> $expected
     * @dataProvider syllableWords
     */
    public function testSyllables(string $word, array $expected): void
    {
        self::assertSame($expected, Syllabifier::word($word));
    }
}
