<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\LinkPreview;

use TurkiyeApi\Http\ApiException;

/**
 * HTTP client for fetching user-supplied URLs without SSRF exposure:
 * http/https on standard ports only, every resolved address must be public,
 * the connection is pinned to the checked address (no DNS rebinding),
 * redirects are followed manually and re-checked, and downloads are capped.
 */
class SafeHttpClient
{
    private const MAX_REDIRECTS = 5;

    /** @var non-empty-string */
    private string $userAgent;
    private int $maxBytes;
    private int $timeout;

    /** @var list<int> */
    private array $allowedPorts;

    /** @param list<int> $allowedPorts */
    public function __construct(string $userAgent, int $maxBytes = 1048576, int $timeout = 6, array $allowedPorts = [80, 443])
    {
        $this->userAgent = $userAgent !== '' ? $userAgent : 'turkiye-api';
        $this->maxBytes = $maxBytes;
        $this->timeout = $timeout;
        $this->allowedPorts = $allowedPorts;
    }

    /**
     * @return array{url: string, status: int, content_type: string, body: string}
     */
    public function get(string $url): array
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            [$host, $port, $ip] = $this->check($url);
            $response = $this->request($url, $host, $port, $ip);
            if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
                $url = UrlResolver::resolve($url, $response['location']);
                continue;
            }
            return [
                'url' => $url,
                'status' => $response['status'],
                'content_type' => $response['content_type'],
                'body' => $response['body'],
            ];
        }
        throw new ApiException(422, 'too-many-redirects', 'The URL redirects too many times.');
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port, pinned IP
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw ApiException::badRequest('Only absolute http(s) URLs are allowed.', 'url');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ApiException::badRequest('URLs with credentials are not allowed.', 'url');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->allowedPorts, true)) {
            throw ApiException::badRequest('Only the standard ports 80 and 443 are allowed.', 'url');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new ApiException(422, 'blocked-address', 'This host is not publicly reachable.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new ApiException(422, 'dns-error', 'The host name could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ApiException(422, 'blocked-address', 'This host resolves to a private or reserved address.');
            }
        }
        return [$host, $port, $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // IPv4-mapped / -compatible IPv6 addresses are checked as IPv4.
        if (preg_match('/^::(ffff:)?(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::isPublicIp($m[2]);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
            '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
            '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
            '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        ];
        foreach ($blocked as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $netBin = inet_pton($net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            } elseif (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }
        // Prefer IPv4 for the pinned connection; all addresses were checked anyway.
        usort($ips, static fn ($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));
        return array_values(array_unique($ips));
    }

    /**
     * @return array{status: int, content_type: string, location: string, body: string}
     */
    protected function request(string $url, string $host, int $port, string $ip): array
    {
        $body = '';
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1', 'Accept-Language: tr,en;q=0.8'],
            // Ignore HTTP(S)_PROXY from the environment: a proxy would resolve the
            // host itself and bypass the address pinning below.
            CURLOPT_PROXY => '',
            // Connect to the address we validated, not whatever DNS says now.
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // new response block
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                // Returning a short count aborts the transfer once enough is read.
                return strlen($body) > $this->maxBytes ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($ok === false && $errno !== CURLE_WRITE_ERROR) {
            throw new ApiException(502, 'fetch-failed', $errno === CURLE_OPERATION_TIMEDOUT
                ? 'The site did not respond in time.'
                : 'The site could not be reached.');
        }
        return [
            'status' => $status,
            'content_type' => $headers['content-type'] ?? '',
            'location' => $headers['location'] ?? '',
            'body' => substr($body, 0, $this->maxBytes),
        ];
    }
}
