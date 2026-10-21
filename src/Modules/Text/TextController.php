<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Text;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;

/**
 * Text endpoints accept `text` either as a query parameter (GET) or in a
 * JSON body (POST) for longer input.
 */
final class TextController
{
    private const MAX_LENGTH = 10000;

    public function case(Request $request): Response
    {
        $text = $this->text($request);
        $to = $request->input('to') ?? 'upper';
        $converters = ['upper' => 'upper', 'lower' => 'lower', 'title' => 'title', 'sentence' => 'sentence', 'ascii' => 'asciiFold'];
        if (!isset($converters[$to])) {
            throw ApiException::badRequest('`to` must be upper, lower, title, sentence or ascii.', 'to');
        }
        $result = (string) call_user_func([TurkishText::class, $converters[$to]], $text);
        return Response::data(['text' => $text, 'to' => $to, 'result' => $result], [], 86400);
    }

    public function slug(Request $request): Response
    {
        $text = $this->text($request);
        $separator = $request->input('separator') ?? '-';
        if (!in_array($separator, ['-', '_', '.'], true)) {
            throw ApiException::badRequest('`separator` must be "-", "_" or ".".', 'separator');
        }
        return Response::data(['text' => $text, 'slug' => TurkishText::slug($text, $separator)], [], 86400);
    }

    public function numberToWords(Request $request): Response
    {
        $number = trim($request->required('number'));
        $currency = $request->input('currency');
        try {
            if ($currency === null || $currency === '') {
                $words = NumberToWords::plain($number);
                $data = ['number' => $number, 'words' => $words];
            } else {
                $money = NumberToWords::money($number, $currency);
                $data = [
                    'number' => $number,
                    'currency' => strtoupper($currency),
                    'words' => $money['words'],
                    'invoice' => '#' . NumberToWords::compact($money['words']) . '#',
                ];
            }
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage() === 'Unsupported currency.'
                ? '`currency` must be one of: ' . implode(', ', array_keys(NumberToWords::CURRENCIES)) . '.'
                : '`number` is not a valid number: ' . $e->getMessage() . ' Use 1250.50 or 1.250,50.';
            throw ApiException::badRequest($msg, str_starts_with($msg, '`currency`') ? 'currency' : 'number');
        }
        $data['upper'] = TurkishText::upper($data['words']);
        return Response::data($data, [], 86400);
    }

    public function syllables(Request $request): Response
    {
        $text = $this->text($request);
        $words = Syllabifier::text($text);
        return Response::data([
            'text' => $text,
            'words' => $words,
            'hyphenated' => implode(' ', array_map(static fn ($w) => implode('-', $w['syllables']), $words)),
        ], [], 86400);
    }

    private function text(Request $request): string
    {
        $text = $request->input('text');
        if ($text === null || $text === '') {
            throw ApiException::badRequest('`text` is required.', 'text');
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw ApiException::badRequest('`text` must be valid UTF-8.', 'text');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ApiException::badRequest('`text` can be at most ' . self::MAX_LENGTH . ' characters.', 'text');
        }
        return $text;
    }
}
