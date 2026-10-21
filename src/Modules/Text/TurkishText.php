<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Text;

/**
 * Locale-correct Turkish case conversion and slugging.
 *
 * PHP's mb_strtoupper/mb_strtolower follow the Unicode default mapping,
 * which turns "i" into "I" and "I" into "i". Turkish needs i ↔ İ and ı ↔ I.
 */
final class TurkishText
{
    private const TO_UPPER = ['i' => 'İ', 'ı' => 'I'];
    private const TO_LOWER = ['İ' => 'i', 'I' => 'ı'];

    private const ASCII_FOLD = [
        'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
        'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
        'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I', 'û' => 'u', 'Û' => 'U',
    ];

    public static function upper(string $text): string
    {
        return mb_strtoupper(strtr($text, self::TO_UPPER), 'UTF-8');
    }

    public static function lower(string $text): string
    {
        // "I" followed by a combining dot (U+0307) is a decomposed "İ".
        $text = str_replace("I\u{0307}", 'i', $text);
        return mb_strtolower(strtr($text, self::TO_LOWER), 'UTF-8');
    }

    /**
     * Capitalises every word: "İSTANBUL'DA ılık BİR gün" → "İstanbul'da Ilık Bir Gün".
     */
    public static function title(string $text): string
    {
        $lower = self::lower($text);
        return (string) preg_replace_callback(
            "/(^|[\\s\\-\\/(\\[\"“])(\\p{Ll})/u",
            static fn (array $m) => $m[1] . self::upper($m[2]),
            $lower,
        );
    }

    /**
     * Lowercases everything and capitalises the first letter of each sentence.
     */
    public static function sentence(string $text): string
    {
        $lower = self::lower($text);
        return (string) preg_replace_callback(
            '/(^\s*|[.!?…]\s+)(\p{Ll})/u',
            static fn (array $m) => $m[1] . self::upper($m[2]),
            $lower,
        );
    }

    public static function asciiFold(string $text): string
    {
        $text = strtr($text, self::ASCII_FOLD);
        // Pure ASCII after the Turkish map needs no further work (the common case).
        if (!preg_match('/[^\x00-\x7F]/', $text)) {
            return $text;
        }
        static $transliterator = false;
        if ($transliterator === false) {
            $transliterator = class_exists(\Transliterator::class) ? \Transliterator::create('Any-Latin; Latin-ASCII') : null;
        }
        if ($transliterator !== null) {
            $converted = $transliterator->transliterate($text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return $text;
    }

    public static function slug(string $text, string $separator = '-'): string
    {
        $text = str_replace('&', ' ve ', $text);
        $text = self::asciiFold(self::lower($text));
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', $separator, $text);
        $quoted = preg_quote($separator, '/');
        $text = (string) preg_replace('/' . $quoted . '{2,}/', $separator, $text);
        return trim($text, $separator);
    }

    /**
     * Normalised form for case- and accent-insensitive search.
     */
    public static function searchKey(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(self::asciiFold(self::lower($text)))));
    }
}
