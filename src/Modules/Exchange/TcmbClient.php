<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\Exchange;

/**
 * Reads the Central Bank of the Republic of Türkiye (TCMB) daily
 * indicative exchange-rate bulletin (kurlar/YYYYMM/DDMMYYYY.xml).
 */
class TcmbClient
{
    private const BASE = 'https://www.tcmb.gov.tr/kurlar';

    private string $userAgent;

    public function __construct(string $userAgent = 'turkiye-api')
    {
        $this->userAgent = $userAgent;
    }

    /**
     * Returns the parsed bulletin, or null when TCMB published none for
     * that date (weekend, holiday, or not published yet today).
     *
     * @return array{date: string, bulletin_no: string, rates: list<array<string, mixed>>}|null
     */
    public function fetch(?\DateTimeImmutable $date): ?array
    {
        $url = $date === null
            ? self::BASE . '/today.xml'
            : sprintf('%s/%s/%s.xml', self::BASE, $date->format('Ym'), $date->format('dmY'));

        [$status, $body] = $this->get($url);
        if ($status === 404) {
            return null;
        }
        if ($status !== 200 || $body === '') {
            throw new \RuntimeException("TCMB responded with HTTP $status.");
        }
        return self::parse($body);
    }

    /**
     * @return array{date: string, bulletin_no: string, rates: list<array<string, mixed>>}
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($doc === false || $doc->getName() !== 'Tarih_Date') {
            throw new \RuntimeException('Unexpected TCMB XML format.');
        }
        $date = \DateTimeImmutable::createFromFormat('!m/d/Y', (string) $doc['Date']);
        if ($date === false) {
            throw new \RuntimeException('TCMB bulletin has no valid date.');
        }

        $num = static function (\SimpleXMLElement $node): ?float {
            $text = trim((string) $node);
            return $text === '' ? null : (float) $text;
        };

        $rates = [];
        foreach ($doc->Currency as $c) {
            $code = (string) $c['CurrencyCode'];
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                continue;
            }
            $rates[] = [
                'code' => $code,
                'unit' => max(1, (int) $c->Unit),
                'name_tr' => trim((string) $c->Isim),
                'name_en' => trim((string) $c->CurrencyName),
                'forex_buying' => $num($c->ForexBuying),
                'forex_selling' => $num($c->ForexSelling),
                'banknote_buying' => $num($c->BanknoteBuying),
                'banknote_selling' => $num($c->BanknoteSelling),
            ];
        }
        if ($rates === []) {
            throw new \RuntimeException('TCMB bulletin contains no currencies.');
        }

        return [
            'date' => $date->format('Y-m-d'),
            'bulletin_no' => (string) $doc['Bulten_No'],
            'rates' => $rates,
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException("Could not reach TCMB: $error");
        }
        // TCMB redirects missing bulletins to an HTML error page instead of a 404.
        if ($status >= 300 && $status < 400) {
            return [404, ''];
        }
        if ($status === 200 && !str_contains(substr((string) $body, 0, 500), 'Tarih_Date')) {
            return [404, ''];
        }
        return [$status, (string) $body];
    }
}
