# Türkiye API

[![CI](https://github.com/lupusoftco/turkiye-api/actions/workflows/ci.yml/badge.svg)](https://github.com/lupusoftco/turkiye-api/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-7.4%20%E2%80%93%208.4-777bb4)
![PHPStan](https://img.shields.io/badge/PHPStan-level%208-2a5ea7)
![License](https://img.shields.io/badge/license-MIT-green)

**An open-source REST API for the Turkey-specific things every developer ends up rebuilding:**
exchange rates, public holidays and business days, addresses and postal codes, ID and IBAN
validation, Turkish text handling, QR codes and link previews.

[Türkçe README](README.tr.md) · [API reference](https://lupusoft.com/api/docs/) · [OpenAPI spec](openapi.yaml)

```bash
curl "https://lupusoft.com/api/v1/business-days/add?date=2026-10-23&days=5"
```
```json
{ "data": { "date": "2026-10-23", "days": 5, "result": "2026-11-02", "result_weekday": "Pazartesi" } }
```

## What's inside

| Module | Endpoints | Highlights |
|---|---|---|
| **Exchange rates** | `/v1/exchange/rates`, `/rates/{code}`, `/rates/{code}/history`, `/convert` | Official TCMB bulletins, historical dates back to 1996, weekend/holiday fallback to the last published bulletin, cross-currency conversion, correct handling of per-100 units (JPY). |
| **Holidays & business days** | `/v1/holidays`, `/holidays/check`, `/v1/business-days/count`, `/business-days/add` | Law No. 2429 rules including half days (arife, 28 October from 13:00), historical changes (1 May since 2009, 15 July since 2017), Hijri-calendar bayrams that cross New Year, overlapping holidays, optional Saturday work week. |
| **Address** | `/v1/address/cities`, `/cities/{city}/districts`, `/districts/{id}/neighbourhoods`, `/postal-codes/{code}`, `/search`, `/distance` | 81 provinces, 973 districts, 73,305 neighbourhoods with postal codes; accent- and case-insensitive autocomplete (`sisli` finds `Şişli`); road distances between provinces. |
| **Validation** | `POST /v1/validate/{tckn,vkn,iban,phone,plate}` | TCKN and VKN checksums, IBAN (70 countries, Turkish bank lookup), phone type and E.164 formatting, vehicle plates. Batch up to 100 values. POST-only so personal numbers never land in URLs or logs. |
| **Text** | `/v1/text/case`, `/slug`, `/number-to-words`, `/syllables` | `i/İ` and `ı/I`-correct casing, slugs, invoice-style amounts in words (`#BinİkiYüzElliTürkLirasıElliKuruş#`), syllabification. |
| **QR codes** | `/v1/qr` | SVG or PNG, colours, error-correction level, transparent background. The URL works directly in `<img src>`. |
| **Link preview** | `/v1/link-preview` | Open Graph / Twitter Card / HTML metadata with SSRF protection and 24-hour caching. |

The full reference with request examples is served at `/docs`, in Turkish and English (from [`openapi.yaml`](openapi.yaml) and [`openapi.tr.yaml`](openapi.tr.yaml), which `tools/translate-openapi.py` regenerates).

## Design notes

These are the decisions worth reading about if you are reviewing the code.

- **No framework, few dependencies.** A ~100-line router, PDO and one QR library. It runs on
  PHP 7.4 through 8.4 — including shared and Plesk hosting — with nothing but SQLite, and can be
  installed in a sub-directory (`example.com/api/`) next to an existing site. The test suite runs
  on every version in CI.
- **Consistent contract.** Every success is `{ "data", "meta" }`; every error is an
  [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) `application/problem+json` document with the
  offending `parameter`. Rate-limit state is always in `X-RateLimit-*` headers.
- **Exchange rates are stored, not proxied.** Each TCMB bulletin is fetched once and kept in
  SQLite. Days without a bulletin are remembered so TCMB is never asked twice; today's
  not-yet-published bulletin is re-checked at most every 15 minutes. A cron job keeps it warm.
- **Religious holidays are calculated and cross-checked.** Diyanet-announced dates are pinned
  for 2020–2027; other years come from the Umm al-Qura calendar (ICU), which matches Diyanet in
  every pinned year — a unit test enforces this. Calculated dates are flagged `estimated: true`.
- **SSRF-safe link previews.** Only http(s) on ports 80/443; every resolved address must be
  public (private, loopback, link-local, CGNAT, metadata and IPv4-mapped ranges are refused);
  the connection is pinned to the checked IP so DNS rebinding cannot swap it; redirects are
  followed manually and re-checked; downloads are capped at 1 MB.
- **Privacy by default.** Rate-limit buckets use a salted hash of the client IP. Usage
  statistics store only day, consumer id and endpoint group. API keys are stored as SHA-256
  hashes.
- **Tiered rate limits.** A global budget per consumer plus smaller budgets for expensive
  endpoints (link preview, rate history). API keys get higher limits.

## Run it locally

Requirements: PHP 7.4+ with `pdo_sqlite`, `curl`, `mbstring`, `dom`, `simplexml` (and `gd` for PNG QR codes).

```bash
git clone https://github.com/lupusoftco/turkiye-api.git && cd turkiye-api
composer install
cp .env.example .env
composer setup        # creates storage/database.sqlite and imports address data (~1 s)
composer serve        # http://127.0.0.1:8080, docs at /docs/
```

Or with Docker:

```bash
docker compose up -d  # API on http://localhost:8080 plus a container that refreshes rates
```

## Deploy on Plesk / shared hosting

**In a sub-directory of an existing site** (e.g. `https://example.com/api/`, works next to WordPress):

```bash
sh tools/build-upload.sh     # creates dist/api with vendor/ included
```

Upload the contents of `dist/api` into `httpdocs/api/`. That is all: no `.env`, no SSH.
Address data is imported on the first address request, a secret key is generated into
`app/storage/`, and links use the detected URL. `app/` (code, data, SQLite database) is
blocked by `.htaccess`; the web server must be Apache, or nginx proxying to Apache (Plesk's default).

**On its own (sub)domain:** set the document root to `public/`, run `composer install --no-dev -o`.
See [`deploy/nginx.conf`](deploy/nginx.conf) for nginx.

Optional: a scheduled task `45 15,16 * * 1-5  php /path/to/bin/fetch-rates.php` keeps exchange rates
warm (otherwise they are fetched on demand). Backfill history with `php bin/fetch-rates.php --from=2025-01-01`.

## Operating it

```bash
php bin/api-key.php create --name="Acme" --email=dev@acme.com --limit=1200   # key shown once
php bin/api-key.php list
php bin/api-key.php revoke --id=3
php bin/stats.php --days=30      # requests per day and endpoint group
```

Configuration lives in `.env` — see [`.env.example`](.env.example) for every option
(CORS origins, trusted proxies, rate limits, required API keys, feature toggles).

## Development

```bash
composer test       # PHPUnit: 133 tests, unit + HTTP-level feature tests
composer analyse    # PHPStan level 8
```

Feature tests drive the real `App` with an in-memory database and fake TCMB / HTTP clients,
so the suite needs no network.

## Data sources and licences

- Exchange rates: [TCMB indicative exchange rates](https://www.tcmb.gov.tr/kurlar/today.xml).
- Addresses, postal codes and distances: [turkey-neighbourhoods](https://github.com/muratgozel/turkey-neighbourhoods)
  by Murat Gözel, MIT licence ([copy](data/address/LICENSE-turkey-neighbourhoods)).
- Holidays: Law No. 2429; religious holiday dates per the Presidency of Religious Affairs (Diyanet).

Validation is algorithmic only. A valid TCKN checksum does not mean a number belongs to a real
person; this API never queries government systems.

## License

[MIT](LICENSE)
