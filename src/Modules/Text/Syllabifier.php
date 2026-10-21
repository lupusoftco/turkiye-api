<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Text;

/**
 * Turkish syllabification. Every syllable has exactly one vowel; when
 * consonants sit between two vowels, the last consonant starts the next
 * syllable (kar-deş, İs-tan-bul, kont-rol, sa-at).
 */
final class Syllabifier
{
    private const VOWELS = 'aeıioöuüâîûAEIİOÖUÜÂÎÛ';

    /** @return list<string> */
    public static function word(string $word): array
    {
        $chars = mb_str_split($word, 1, 'UTF-8');
        $vowelIdx = [];
        foreach ($chars as $i => $c) {
            if (mb_strpos(self::VOWELS, $c, 0, 'UTF-8') !== false) {
                $vowelIdx[] = $i;
            }
        }
        if (count($vowelIdx) <= 1) {
            return [$word];
        }
        $breaks = [];
        for ($k = 0; $k < count($vowelIdx) - 1; $k++) {
            $a = $vowelIdx[$k];
            $b = $vowelIdx[$k + 1];
            // Adjacent vowels split between them; otherwise before the last consonant.
            $breaks[] = $b - $a === 1 ? $b : $b - 1;
        }
        $syllables = [];
        $start = 0;
        foreach ($breaks as $break) {
            $syllables[] = implode('', array_slice($chars, $start, $break - $start));
            $start = $break;
        }
        $syllables[] = implode('', array_slice($chars, $start));
        return $syllables;
    }

    /**
     * @return list<array{word: string, syllables: list<string>}>
     */
    public static function text(string $text): array
    {
        preg_match_all('/[\p{L}]+/u', $text, $m);
        return array_map(
            static fn (string $w) => ['word' => $w, 'syllables' => self::word($w)],
            $m[0],
        );
    }
}
