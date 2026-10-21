# Error types

Errors are returned as [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem documents
(`Content-Type: application/problem+json`):

```json
{
  "type": "https://github.com/lupusoftco/turkiye-api/blob/main/docs/errors.md#invalid-parameter",
  "title": "Bad Request",
  "status": 400,
  "detail": "`date` must be a valid date in YYYY-MM-DD format.",
  "instance": "/v1/exchange/rates",
  "parameter": "date"
}
```

| Type | Status | Meaning |
|---|---|---|
| <a id="invalid-parameter"></a>`invalid-parameter` | 400 | A parameter is missing or malformed. `parameter` names it. |
| <a id="payload-too-large"></a>`payload-too-large` | 413 | The JSON body is larger than 64 KB. |
| <a id="api-key-required"></a>`api-key-required` | 401 | This deployment requires an API key (`X-API-Key` header). |
| <a id="invalid-api-key"></a>`invalid-api-key` | 401 | The key is unknown or revoked. |
| <a id="not-found"></a>`not-found` | 404 | No such endpoint, province, district, postal code or currency. |
| <a id="method-not-allowed"></a>`method-not-allowed` | 405 | Wrong HTTP method; see the `Allow` header. Validation endpoints are POST-only. |
| <a id="unprocessable"></a>`unprocessable` | 422 | The request is valid but cannot be answered, e.g. TCMB publishes no `forex_selling` rate for that currency. |
| <a id="blocked-address"></a>`blocked-address` | 422 | Link preview refused a URL that resolves to a private or reserved address. |
| <a id="dns-error"></a>`dns-error` | 422 | The link-preview host name does not resolve. |
| <a id="fetch-failed"></a>`fetch-failed` | 422 / 502 | The page returned an error status, or could not be reached. |
| <a id="too-many-redirects"></a>`too-many-redirects` | 422 | More than 5 redirects. |
| <a id="rate-limited"></a>`rate-limited` | 429 | Rate limit exceeded; retry after `Retry-After` seconds. |
| <a id="upstream-error"></a>`upstream-error` | 502 | TCMB could not be reached. |
| <a id="no-bulletin"></a>`no-bulletin` | 503 | No TCMB bulletin was found within 10 days of the requested date. |
| <a id="data-not-imported"></a>`data-not-imported` | 503 | Address data was not imported; run `php bin/setup.php`. |
| <a id="png-unavailable"></a>`png-unavailable` | 503 | The server lacks the GD extension; use `format=svg`. |
| <a id="internal-error"></a>`internal-error` | 500 | Unexpected error. Details are logged, not returned (unless `APP_DEBUG=true`). |
