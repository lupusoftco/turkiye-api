<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Validation;

final class IbanData
{
    /**
     * IBAN length per country (ISO 13616 registry, common countries).
     *
     * @var array<string, int>
     */
    public const LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22,
        'BH' => 22, 'BR' => 29, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18,
        'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22,
        'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28,
        'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19,
        'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29,
        'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24,
        'SM' => 27, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VG' => 24, 'XK' => 20,
    ];

    /**
     * Bank codes (EFT codes) of banks in Turkey, positions 5–9 of a TR IBAN.
     * Best-effort list of commonly used banks; unknown codes return null.
     *
     * @var array<string, string>
     */
    public const TR_BANKS = [
        '00010' => 'T.C. Ziraat Bankası',
        '00012' => 'Türkiye Halk Bankası',
        '00015' => 'Türkiye Vakıflar Bankası',
        '00032' => 'Türk Ekonomi Bankası',
        '00046' => 'Akbank',
        '00059' => 'Şekerbank',
        '00062' => 'Türkiye Garanti Bankası',
        '00064' => 'Türkiye İş Bankası',
        '00067' => 'Yapı ve Kredi Bankası',
        '00092' => 'Citibank',
        '00099' => 'ING Bank',
        '00103' => 'Fibabanka',
        '00111' => 'QNB Bank',
        '00123' => 'HSBC Bank',
        '00124' => 'Alternatifbank',
        '00125' => 'Burgan Bank',
        '00134' => 'Denizbank',
        '00135' => 'Anadolubank',
        '00143' => 'Aktif Yatırım Bankası',
        '00146' => 'Odea Bank',
        '00203' => 'Albaraka Türk Katılım Bankası',
        '00205' => 'Kuveyt Türk Katılım Bankası',
        '00206' => 'Türkiye Finans Katılım Bankası',
        '00209' => 'Ziraat Katılım Bankası',
        '00210' => 'Vakıf Katılım Bankası',
        '00211' => 'Türkiye Emlak Katılım Bankası',
    ];

    /**
     * Geographic area codes for Turkish landlines.
     *
     * @var list<string>
     */
    public const AREA_CODES = [
        '212', '216', '222', '224', '226', '228', '232', '236', '242', '246', '248', '252', '256', '258',
        '262', '264', '266', '272', '274', '276', '282', '284', '286', '288', '312', '318', '322', '324',
        '326', '328', '332', '338', '342', '344', '346', '348', '352', '354', '356', '358', '362', '364',
        '366', '368', '370', '372', '374', '376', '378', '380', '382', '384', '386', '388', '412', '414',
        '416', '422', '424', '426', '428', '432', '434', '436', '438', '442', '446', '452', '454', '456',
        '458', '462', '464', '466', '472', '474', '476', '478', '482', '484', '486', '488',
    ];
}
