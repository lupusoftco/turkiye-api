<?php

declare(strict_types=1);

namespace TurkiyeApi;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;
use TurkiyeApi\Http\Router;
use TurkiyeApi\Modules\Address\AddressController;
use TurkiyeApi\Modules\Address\AddressImporter;
use TurkiyeApi\Modules\Address\AddressRepository;
use TurkiyeApi\Modules\Exchange\ExchangeController;
use TurkiyeApi\Modules\Exchange\ExchangeService;
use TurkiyeApi\Modules\Exchange\TcmbClient;
use TurkiyeApi\Modules\Holidays\HolidayCalendar;
use TurkiyeApi\Modules\Holidays\HolidayController;
use TurkiyeApi\Modules\LinkPreview\LinkPreviewController;
use TurkiyeApi\Modules\LinkPreview\SafeHttpClient;
use TurkiyeApi\Modules\Qr\QrController;
use TurkiyeApi\Modules\Text\TextController;
use TurkiyeApi\Modules\Validation\ValidationController;
use TurkiyeApi\Storage\ApiKeys;
use TurkiyeApi\Storage\Cache;
use TurkiyeApi\Storage\Database;
use TurkiyeApi\Storage\RateLimiter;
use TurkiyeApi\Storage\Usage;

final class App
{
    public const VERSION = '1.0.0';

    public Config $config;

    private ?Database $db = null;
    private ?Router $router = null;
    private ?TcmbClient $tcmb;
    private ?SafeHttpClient $http;

    /** Base URL of the current request, used for links when APP_URL is not set. */
    private string $requestBaseUrl = '';

    /** @var array<string, int> Requests per window for each route group (anonymous callers). */
    private array $groupLimits;

    public function __construct(Config $config, ?TcmbClient $tcmb = null, ?SafeHttpClient $http = null)
    {
        $this->config = $config;
        $this->tcmb = $tcmb;
        $this->http = $http;
        $this->groupLimits = [
            'link-preview' => $config->int('RATE_LIMIT_LINK_PREVIEW', 10),
            'exchange-history' => $config->int('RATE_LIMIT_EXCHANGE_HISTORY', 20),
        ];
    }

    public function db(): Database
    {
        if ($this->db === null) {
            $path = $this->config->string('DB_PATH', 'storage/database.sqlite');
            $this->db = new Database($path === ':memory:' ? $path : $this->config->path('DB_PATH', 'storage/database.sqlite'));
            $this->db->migrate();
        }
        return $this->db;
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $rateHeaders = [];
        $this->requestBaseUrl = $request->basePath !== '' || $request->header('host') !== '' ? $request->baseUrl() : '';
        try {
            if ($request->method === 'OPTIONS') {
                $response = new Response('', 204, [
                    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-API-Key',
                    'Access-Control-Max-Age' => '86400',
                ]);
            } else {
                $route = $this->router()->match($request->method, $request->path);
                $consumer = $this->authenticate($request);
                if ($route['group'] !== 'meta') {
                    $rateHeaders = $this->throttle($request, $consumer, $route['group']);
                    if ($this->config->bool('USAGE_STATS', true)) {
                        (new Usage($this->db()))->record($consumer['id'], $route['group']);
                    }
                }
                $response = ($route['handler'])($request->withRouteParams($route['params']));
            }
        } catch (ApiException $e) {
            $response = Response::problem($e, $request->path);
        } catch (\Throwable $e) {
            error_log(sprintf('[turkiye-api] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            $detail = $this->config->bool('APP_DEBUG', false) ? $e->getMessage() : 'An unexpected error occurred.';
            $response = Response::problem(new ApiException(500, 'internal-error', $detail), $request->path);
        }

        $response->headers += $rateHeaders + $this->corsHeaders($request) + [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Response-Time' => sprintf('%.1fms', (hrtime(true) - $started) / 1e6),
        ];
        return $response;
    }

    /**
     * @return array{id: string, limit: int}
     */
    private function authenticate(Request $request): array
    {
        $key = $request->header('x-api-key');
        if ($key === '' && preg_match('/^Bearer\s+(\S+)$/i', $request->header('authorization'), $m)) {
            $key = $m[1];
        }
        $anonLimit = $this->config->int('RATE_LIMIT_ANON', 60);
        if ($key === '') {
            if ($this->config->bool('REQUIRE_API_KEY', false)) {
                throw new ApiException(401, 'api-key-required', 'An API key is required. Send it in the X-API-Key header.');
            }
            return ['id' => 'ip:' . hash('sha256', $this->clientIp($request) . $this->appKey()), 'limit' => $anonLimit];
        }
        $found = (new ApiKeys($this->db()))->find($key);
        if ($found === null) {
            throw new ApiException(401, 'invalid-api-key', 'This API key is invalid or has been revoked.');
        }
        return ['id' => 'key:' . $found['id'], 'limit' => $found['rate_limit'] ?? $this->config->int('RATE_LIMIT_KEY', 600)];
    }

    /**
     * @param array{id: string, limit: int} $consumer
     * @return array<string, string>
     */
    private function throttle(Request $request, array $consumer, string $group): array
    {
        $window = $this->config->int('RATE_LIMIT_WINDOW', 60);
        $limiter = new RateLimiter($this->db());
        $state = $limiter->hit($consumer['id'], $consumer['limit'], $window);

        // Expensive endpoints get their own, smaller budget (scaled up for keys).
        if (isset($this->groupLimits[$group])) {
            $groupLimit = $this->groupLimits[$group];
            if (str_starts_with($consumer['id'], 'key:')) {
                $groupLimit *= 10;
            }
            $groupState = $limiter->hit($consumer['id'] . '|' . $group, $groupLimit, $window);
            $allowed = $state['allowed'] && $groupState['allowed'];
            // Report whichever budget is closer to running out.
            $state = $groupState['remaining'] < $state['remaining'] ? $groupState : $state;
            $state['allowed'] = $allowed;
        }

        $headers = [
            'X-RateLimit-Limit' => (string) $state['limit'],
            'X-RateLimit-Remaining' => (string) $state['remaining'],
            'X-RateLimit-Reset' => (string) $state['reset'],
        ];
        if (!$state['allowed']) {
            $retry = max(1, $state['reset'] - time());
            throw new ApiException(429, 'rate-limited', "Rate limit exceeded. Try again in $retry seconds, or use an API key for higher limits.", [], $headers + ['Retry-After' => (string) $retry]);
        }
        return $headers;
    }

    private function clientIp(Request $request): string
    {
        $remote = $request->remoteAddr;
        $trusted = $this->config->list('TRUSTED_PROXIES');
        if ($trusted !== [] && in_array($remote, $trusted, true)) {
            $forwarded = array_map('trim', explode(',', $request->header('x-forwarded-for')));
            // Walk from the right: the first address that is not a trusted proxy is the client.
            foreach (array_reverse($forwarded) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false && !in_array($ip, $trusted, true)) {
                    return $ip;
                }
            }
        }
        return $remote;
    }

    /** @return array<string, string> */
    private function corsHeaders(Request $request): array
    {
        $allowed = $this->config->list('CORS_ORIGINS', '*');
        if (in_array('*', $allowed, true)) {
            return ['Access-Control-Allow-Origin' => '*', 'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After'];
        }
        $origin = $request->header('origin');
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After'];
        }
        return ['Vary' => 'Origin'];
    }

    public function exchangeService(): ExchangeService
    {
        return new ExchangeService(
            $this->db(),
            $this->tcmb ?? new TcmbClient($this->userAgent()),
            $this->config->bool('TCMB_FETCH_ON_DEMAND', true),
        );
    }

    private function userAgent(): string
    {
        return $this->config->string('HTTP_USER_AGENT', 'TurkiyeApi/' . self::VERSION . ' (+https://github.com/lupusoftco/turkiye-api)');
    }

    private function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $r = new Router();
        $root = $this->config->rootDir();

        $r->get('/', fn () => $this->index(), 'meta');
        $r->get('/health', fn () => Response::data(['status' => 'ok', 'version' => self::VERSION]), 'meta');
        $r->get('/openapi.yaml', fn () => $this->openApi($root . '/openapi.yaml'), 'meta');
        $r->get('/openapi.tr.yaml', fn () => $this->openApi($root . '/openapi.tr.yaml'), 'meta');

        $fx = fn () => new ExchangeController($this->exchangeService());
        $r->get('/v1/exchange/rates', fn (Request $q) => $fx()->rates($q), 'exchange');
        $r->get('/v1/exchange/rates/{code:[A-Za-z]{3}}', fn (Request $q) => $fx()->currency($q), 'exchange');
        $r->get('/v1/exchange/rates/{code:[A-Za-z]{3}}/history', fn (Request $q) => $fx()->history($q), 'exchange-history');
        $r->get('/v1/exchange/convert', fn (Request $q) => $fx()->convert($q), 'exchange');

        $calendar = new HolidayCalendar();
        $holidays = new HolidayController($calendar);
        $r->get('/v1/holidays', [$holidays, 'list'], 'holidays');
        $r->get('/v1/holidays/check', [$holidays, 'check'], 'holidays');
        $r->get('/v1/business-days/count', [$holidays, 'count'], 'holidays');
        $r->get('/v1/business-days/add', [$holidays, 'add'], 'holidays');

        $address = fn () => new AddressController($this->addressRepository());
        $r->get('/v1/address/cities', fn (Request $q) => $address()->cities($q), 'address');
        $r->get('/v1/address/cities/{city}', fn (Request $q) => $address()->city($q), 'address');
        $r->get('/v1/address/cities/{city}/districts', fn (Request $q) => $address()->districts($q), 'address');
        $r->get('/v1/address/cities/{city}/districts/{district}/neighbourhoods', fn (Request $q) => $address()->cityDistrictNeighbourhoods($q), 'address');
        $r->get('/v1/address/districts/{id:\d+}/neighbourhoods', fn (Request $q) => $address()->neighbourhoods($q), 'address');
        $r->get('/v1/address/postal-codes/{code:\d{5}}', fn (Request $q) => $address()->postalCode($q), 'address');
        $r->get('/v1/address/search', fn (Request $q) => $address()->search($q), 'address');
        $r->get('/v1/address/distance', fn (Request $q) => $address()->distance($q), 'address');

        $validation = new ValidationController($root . '/data/address/cities.csv');
        $r->post('/v1/validate/{type:[a-z]+}', [$validation, 'handle'], 'validate');

        $text = new TextController();
        foreach (['case' => 'case', 'slug' => 'slug', 'number-to-words' => 'numberToWords', 'syllables' => 'syllables'] as $path => $method) {
            $r->get("/v1/text/$path", [$text, $method], 'text');
            $r->post("/v1/text/$path", [$text, $method], 'text');
        }

        $r->get('/v1/qr', [new QrController(), 'image'], 'qr');

        if ($this->config->bool('LINK_PREVIEW_ENABLED', true)) {
            $r->get('/v1/link-preview', fn (Request $q) => (new LinkPreviewController(
                $this->http ?? new SafeHttpClient($this->userAgent()),
                new Cache($this->db()),
            ))->preview($q), 'link-preview');
        }

        return $this->router = $r;
    }

    /**
     * Public base URL: APP_URL when set, otherwise derived from the request.
     */
    public function baseUrl(): string
    {
        return rtrim($this->config->string('APP_URL', $this->requestBaseUrl), '/');
    }

    /**
     * Secret used to pseudonymise IPs. Taken from APP_KEY, or generated once
     * and kept in the storage directory so no configuration is required.
     */
    private function appKey(): string
    {
        $key = $this->config->string('APP_KEY', '');
        if ($key !== '' && $key !== 'change-me') {
            return $key;
        }
        $dbPath = $this->config->string('DB_PATH', 'storage/database.sqlite');
        if ($dbPath === ':memory:') {
            return 'turkiye-api';
        }
        $file = dirname($this->config->path('DB_PATH', 'storage/database.sqlite')) . '/app.key';
        if (is_file($file)) {
            return trim((string) file_get_contents($file));
        }
        $key = bin2hex(random_bytes(16));
        @file_put_contents($file, $key, LOCK_EX);
        return $key;
    }

    /**
     * Address repository; imports the bundled address data on first use, so
     * the API works right after upload without running bin/setup.php.
     */
    private function addressRepository(): AddressRepository
    {
        $repo = new AddressRepository($this->db());
        if ($repo->isImported() || !$this->config->bool('AUTO_SETUP', true)) {
            return $repo;
        }
        $dbPath = $this->config->string('DB_PATH', 'storage/database.sqlite');
        $lock = $dbPath === ':memory:' ? false : @fopen(dirname($this->config->path('DB_PATH', $dbPath)) . '/setup.lock', 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }
        try {
            // Re-check under the lock: another request may have imported meanwhile.
            if (!(new AddressRepository($this->db()))->isImported()) {
                (new AddressImporter($this->db(), $this->config->rootDir() . '/data/address'))->import();
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        return $repo;
    }

    /**
     * Serves openapi.yaml with the server URL pointing at this installation.
     */
    private function openApi(string $file): Response
    {
        $yaml = (string) file_get_contents($file);
        $base = $this->baseUrl();
        if ($base !== '') {
            $yaml = (string) preg_replace('#^servers:\n  - url: /\n#m', "servers:\n  - url: $base\n", $yaml, 1);
        }
        // Short cache so documentation changes show up quickly after a deploy.
        return Response::raw($yaml, 'application/yaml; charset=utf-8', 300);
    }

    private function index(): Response
    {
        $base = $this->baseUrl();
        return Response::data([
            'name' => 'Türkiye API',
            'version' => self::VERSION,
            'docs' => $base . '/docs/',
            'openapi' => $base . '/openapi.yaml',
            'source' => 'https://github.com/lupusoftco/turkiye-api',
            'modules' => [
                'exchange' => $base . '/v1/exchange/rates',
                'holidays' => $base . '/v1/holidays',
                'business_days' => $base . '/v1/business-days/count?from=2026-01-01&to=2026-12-31',
                'address' => $base . '/v1/address/cities',
                'validate' => $base . '/v1/validate/{tckn|vkn|iban|phone|plate} (POST)',
                'text' => $base . '/v1/text/number-to-words?number=1250.50&currency=TRY',
                'qr' => $base . '/v1/qr?data=https://example.com',
                'link_preview' => $base . '/v1/link-preview?url=https://example.com',
            ],
        ], [], 3600);
    }
}
