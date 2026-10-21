# Contributing

Thanks for helping! Issues and pull requests are welcome in Turkish or English.

1. Fork and create a branch from `main`.
2. `composer install && composer setup`
3. Make your change with tests. Keep the public contract (`data`/`meta`, problem+json errors) intact.
4. Run `composer test` and `composer analyse` — both must pass.
5. If you add or change an endpoint, update `openapi.yaml` and `CHANGELOG.md`.

**Holiday dates:** when Diyanet announces a new year's calendar, add it to
`HolidayCalendar::VERIFIED` with a link to the announcement in the PR. The test suite checks
that the calculated calendar still agrees with every pinned year.

**Bank codes:** additions to `IbanData::TR_BANKS` should cite the bank's EFT code source.
