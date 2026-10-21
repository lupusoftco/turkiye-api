# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-23

### Added
- Runs on PHP 7.4 – 8.4; installable in a sub-directory (e.g. `example.com/api/`) with zero configuration.
- Exchange rates from TCMB: daily rates, single currency, history and conversion.
- Public holidays (Law No. 2429) with half days and Hijri-calendar bayrams; business-day count and add.
- Provinces, districts, neighbourhoods, postal codes, search and inter-province distances.
- Validation of TCKN, VKN, IBAN, phone numbers and vehicle plates, single or batch.
- Turkish text tools: case conversion, slugs, number to words, syllables.
- QR codes (SVG/PNG) and SSRF-safe link previews.
- Documentation in Turkish and English with a language switch (`openapi.tr.yaml`).
- API keys, tiered rate limiting, CORS, usage statistics, OpenAPI 3.1 spec and docs page.
