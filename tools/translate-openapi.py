#!/usr/bin/env python3
"""Builds openapi.tr.yaml (Turkish docs) from openapi.yaml.

    python3 tools/translate-openapi.py

Every English string must have a translation; the script fails loudly when
openapi.yaml changes and a phrase is no longer found, so the two stay in sync.
"""
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent

# (English, Turkish). Longest/most specific first; applied in order.
T = [
    # --- info ---
    ("summary: Open-source REST API for Turkey-specific developer needs.",
     "summary: Türkiye'ye özgü geliştirici ihtiyaçları için açık kaynak REST API."),
    ("""    Exchange rates from TCMB, public holidays and business days, provinces /
    districts / neighbourhoods / postal codes, TCKN / VKN / IBAN / phone / plate
    validation, Turkish text tools, QR codes and link previews.

    **Conventions**
    - Successful responses are wrapped as `{ "data": ..., "meta": {...} }`.
    - Errors use [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) `application/problem+json`.
    - Dates are `YYYY-MM-DD` in the Europe/Istanbul time zone.
    - Anonymous use is rate-limited per IP. Send an API key in `X-API-Key`
      (or `Authorization: Bearer ...`) for higher limits. Every response
      carries `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`.
    - CORS is open, so the API can be called directly from browsers.""",
     """    TCMB döviz kurları, resmi tatiller ve iş günü hesabı, il / ilçe / mahalle /
    posta kodu, TCKN / VKN / IBAN / telefon / plaka doğrulama, Türkçe metin
    araçları, QR kod ve link önizleme.

    **Kurallar**
    - Başarılı yanıtlar `{ "data": ..., "meta": {...} }` biçimindedir.
    - Hatalar [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) `application/problem+json` formatındadır.
    - Tarihler Europe/Istanbul saat diliminde `YYYY-AA-GG` biçimindedir.
    - Anahtarsız kullanım IP başına sınırlıdır. Daha yüksek limit için API anahtarını
      `X-API-Key` (veya `Authorization: Bearer ...`) başlığıyla gönderin. Her yanıtta
      `X-RateLimit-Limit`, `X-RateLimit-Remaining` ve `X-RateLimit-Reset` başlıkları bulunur.
    - CORS açıktır; API doğrudan tarayıcıdan çağrılabilir."""),
    ("description: This server", "description: Bu sunucu"),

    # --- tags (names must change everywhere they are referenced) ---
    ("- name: Address", "- name: Adres"), ("tags: [Address]", "tags: [Adres]"),
    ("- name: Validation", "- name: Doğrulama"), ("tags: [Validation]", "tags: [Doğrulama]"),
    ("- name: Text", "- name: Metin"), ("tags: [Text]", "tags: [Metin]"),
    ("- name: Meta", "- name: Servis"), ("tags: [Meta]", "tags: [Servis]"),
    ("description: Daily indicative rates published by the Central Bank of the Republic of Türkiye (TCMB).",
     "description: Türkiye Cumhuriyet Merkez Bankası'nın (TCMB) yayımladığı günlük gösterge kurları."),
    ("description: Official holidays under Law No. 2429, with half days and Hijri-calendar bayrams.",
     "description: 2429 sayılı Kanun'a göre resmi tatiller; yarım günler ve Hicri takvime göre bayramlar dahil."),
    ("description: 81 provinces, 973 districts, ~73,000 neighbourhoods with postal codes, and road distances.",
     "description: 81 il, 973 ilçe, posta kodlarıyla ~73.000 mahalle ve iller arası karayolu mesafeleri."),
    ("description: Checksum and format validation. POST only, so personal numbers never appear in URLs or logs.",
     "description: Sağlama ve format doğrulaması. Kişisel numaralar URL'ye ve loglara düşmesin diye yalnızca POST."),
    ("description: Locale-correct Turkish text utilities.", "description: Türkçe kurallarına uygun metin araçları."),
    ("description: SVG or PNG QR codes usable directly as image URLs.",
     "description: Doğrudan görsel adresi olarak kullanılabilen SVG veya PNG QR kodlar."),
    ("description: Title, description, image and favicon of any public web page.",
     "description: Herhangi bir herkese açık sayfanın başlığı, açıklaması, görseli ve favicon'u."),
    ("description: Service information.", "description: Servis bilgileri."),

    # --- exchange ---
    ("summary: All rates for a date", "summary: Bir tarihin tüm kurları"),
    ("description: Returns the bulletin published on `date`, or the latest one before it for weekends and holidays.",
     "description: `date` günü yayımlanan bülteni; hafta sonu ve tatillerde ondan önceki son bülteni döndürür."),
    ("description: Comma-separated ISO 4217 codes to include.", "description: Dahil edilecek ISO 4217 kodları (virgülle ayrılmış)."),
    ("description: Rates in TRY per `unit` of each currency.", "description: Her dövizin `unit` birimi başına TL karşılığı."),
    ("summary: One currency", "summary: Tek döviz"),
    ("description: The rate.", "description: Kur."),
    ("summary: Daily history of a currency", "summary: Bir dövizin günlük geçmişi"),
    ("description: At most 366 days per request. Only days with a published bulletin are returned.",
     "description: İstek başına en fazla 366 gün. Yalnızca bülten yayımlanan günler döner."),
    ("description: Defaults to 30 days before `to`.,", "description: 'Varsayılan: `to` tarihinden 30 gün önce.',"),
    ("description: Defaults to today.,", "description: 'Varsayılan: bugün.',"),
    ("description: Return only one rate type.", "description: Yalnızca bir kur türünü döndürür."),
    ("description: Time series, oldest first.", "description: Zaman serisi, eskiden yeniye."),
    ("summary: Convert an amount", "summary: Tutar çevir"),
    ("description: Cross rates are calculated through TRY. The default rate type is `forex_buying` (döviz alış), which is commonly used for invoicing.",
     "description: Çapraz kurlar TL üzerinden hesaplanır. Varsayılan kur türü, faturalarda yaygın kullanılan `forex_buying` (döviz alış)."),
    ("description: Conversion result.", "description: Çeviri sonucu."),
    ("description: Bulletin date used.", "description: Kullanılan bülten tarihi."),

    # --- holidays ---
    ("summary: Holidays of a year", "summary: Bir yılın tatilleri"),
    ("description: 'Include half-day holidays (arife, 28 October).'", "description: 'Yarım gün tatilleri (arife, 28 Ekim) dahil et.'"),
    ("description: Holidays sorted by date. Overlapping holidays are listed separately.",
     "description: Tarihe göre sıralı tatiller. Çakışan tatiller ayrı ayrı listelenir."),
    ("summary: Describe a date", "summary: Bir tarihi sorgula"),
    ("description: Whether the date is a weekend, holiday, half day or business day.",
     "description: Tarihin hafta sonu, tatil, yarım gün veya iş günü olup olmadığı."),
    ("summary: Count business days in a range", "summary: İki tarih arasındaki iş günlerini say"),
    ("description: Both ends are included. Ranges are limited to 10 years.",
     "description: Başlangıç ve bitiş günleri dahildir. Aralık en fazla 10 yıl olabilir."),
    ("description: Counts.", "description: Sayılar."),
    ("summary: Add business days to a date", "summary: Bir tarihe iş günü ekle"),
    ("description: The start date is not counted; negative `days` go backwards.",
     "description: Başlangıç günü sayılmaz; negatif `days` geriye doğru gider."),
    ("description: Resulting date.", "description: Sonuç tarihi."),

    # --- address ---
    ("summary: All provinces", "summary: Tüm iller"),
    ("description: 81 provinces ordered by plate code.", "description: Plaka koduna göre sıralı 81 il."),
    ("summary: A province with its districts", "summary: Bir il ve ilçeleri"),
    ("description: Province and districts.", "description: İl ve ilçeler."),
    ("summary: Districts of a province", "summary: Bir ilin ilçeleri"),
    ("description: Districts.", "description: İlçeler."),
    ("summary: Neighbourhoods by province and district name", "summary: İl ve ilçe adına göre mahalleler"),
    ("description: District name or slug.,", "description: İlçe adı veya slug.,"),
    ("summary: Neighbourhoods by district id", "summary: İlçe kimliğine göre mahalleler"),
    ("description: Neighbourhoods with postal codes.", "description: Posta kodlarıyla mahalleler."),
    ("summary: Neighbourhoods using a postal code", "summary: Bir posta kodunu kullanan mahalleler"),
    ("description: Matching neighbourhoods with district and province.", "description: İlçe ve iliyle birlikte eşleşen mahalleler."),
    ("summary: Search neighbourhoods (autocomplete)", "summary: Mahalle ara (otomatik tamamlama)"),
    ("description: Case- and Turkish-character-insensitive; prefix matches rank first.",
     "description: Büyük/küçük harf ve Türkçe karakter duyarsız; başı eşleşenler önce gelir."),
    ("description: Plate code or name.,", "description: Plaka kodu veya ad.,"),
    ("description: 'District id, or name together with `city`.'", "description: 'İlçe kimliği ya da `city` ile birlikte ilçe adı.'"),
    ("description: Matches.", "description: Eşleşmeler."),
    ("summary: Road distance between two province centres", "summary: İki il merkezi arasındaki karayolu mesafesi"),
    ("description: Distance in kilometres.", "description: Kilometre cinsinden mesafe."),

    # --- validation ---
    ("summary: Validate a TCKN, VKN, IBAN, phone number or plate", "summary: TCKN, VKN, IBAN, telefon veya plaka doğrula"),
    ("""        Algorithmic checks only — no government system is queried. A valid TCKN
        checksum does not prove that the number belongs to a person.
        Send one value or up to 100 in a batch.""",
     """        Yalnızca algoritmik kontrol yapılır; hiçbir kamu sistemi sorgulanmaz. Geçerli bir
        TCKN sağlaması, numaranın gerçek bir kişiye ait olduğunu kanıtlamaz.
        Tek değer veya toplu olarak en fazla 100 değer gönderilebilir."""),
    ("""            Always includes `valid`, `normalized` and `reason` (why it is invalid).
            Extra fields per type — iban: `country`, `formatted`, `bank_code`, `bank`;
            phone: `type`, `e164`, `national`, `area_code`; plate: `formatted`, `city_code`, `city`.""",
     """            Her zaman `valid`, `normalized` ve `reason` (geçersizlik nedeni) alanlarını içerir.
            Türe göre ek alanlar — iban: `country`, `formatted`, `bank_code`, `bank`;
            telefon: `type`, `e164`, `national`, `area_code`; plaka: `formatted`, `city_code`, `city`."""),
    ("description: Only in batch responses.", "description: Yalnızca toplu yanıtlarda."),

    # --- text ---
    ("summary: Turkish-aware case conversion", "summary: Türkçeye uygun büyük/küçük harf dönüşümü"),
    ("description: Also accepts POST with a JSON body for long text.", "description: Uzun metinler için JSON gövdeli POST da kabul eder."),
    ("summary: URL slug", "summary: URL slug'ı"),
    ("summary: Number to Turkish words", "summary: Sayıyı yazıya çevir"),
    ("""        Accepts `1250.50` or Turkish formatting `1.250,50`. With `currency`, amounts are
        rounded to 2 decimals and an invoice form (`#BinİkiYüzElliTürkLirası...#`) is added.""",
     """        `1250.50` veya Türkçe biçimde `1.250,50` kabul eder. `currency` verilirse tutar 2 haneye
        yuvarlanır ve fatura biçimi (`#BinİkiYüzElliTürkLirası...#`) da eklenir."""),
    ("description: Words.", "description: Yazıyla."),
    ("summary: Split Turkish words into syllables", "summary: Türkçe kelimeleri hecelere ayır"),
    ("description: Result.", "description: Sonuç."),

    # --- qr ---
    ("summary: QR code image", "summary: QR kod görseli"),
    ("description: Returns the image itself, so the URL can be used directly in `<img src>`.",
     "description: Görselin kendisini döndürür; adres doğrudan `<img src>` içinde kullanılabilir."),
    ("description: Approximate width in pixels.,", "description: Piksel cinsinden yaklaşık genişlik.,"),
    ("description: Quiet zone in modules.,", "description: Modül cinsinden kenar boşluğu.,"),
    ("description: Error correction level.,", "description: Hata düzeltme seviyesi.,"),
    ("description: The QR code.", "description: QR kod."),

    # --- link preview ---
    ("summary: Title, description, image and favicon of a URL", "summary: Bir adresin başlığı, açıklaması, görseli ve favicon'u"),
    ("""        Reads Open Graph, Twitter Card and HTML metadata. Results are cached for 24 hours.
        Private and reserved addresses are refused. This endpoint has a smaller rate limit.""",
     """        Open Graph, Twitter Card ve HTML meta verilerini okur. Sonuçlar 24 saat önbelleklenir.
        Özel ve ayrılmış IP adresleri reddedilir. Bu uç noktanın istek limiti daha düşüktür."""),
    ("description: Bypass the cache.,", "description: Önbelleği atla.,"),
    ("description: Metadata.", "description: Meta veriler."),
    ("description: Final URL after redirects.", "description: Yönlendirmeler sonrası son adres."),

    # --- meta & components ---
    ("summary: Health check", "summary: Sağlık kontrolü"),
    # Tag names last, so they do not clash with longer phrases above.
    ("Holidays & business days", "Tatil ve iş günü"),
    ("Exchange rates", "Döviz kurları"),
    ("Link preview", "Link önizleme"),
    ("QR codes", "QR kod"),
    ("description: Requests allowed in the current window.", "description: Mevcut zaman penceresinde izin verilen istek sayısı."),
    ("description: Requests left in the current window.", "description: Mevcut pencerede kalan istek sayısı."),
    ("description: Unix time when the window resets.", "description: Pencerenin sıfırlanacağı Unix zamanı."),
    ("description: Defaults to today (Europe/Istanbul).", "description: 'Varsayılan: bugün (Europe/Istanbul).'"),
    ("description: Plate code (`34`, `6`) or name (`istanbul`, `İstanbul`).", "description: Plaka kodu (`34`, `6`) veya ad (`istanbul`, `İstanbul`)."),
    ("""description: 'Up to 10,000 characters. Use POST with `{"text": "..."}` for long input.'""",
     """description: 'En fazla 10.000 karakter. Uzun metinler için `{"text": "..."}` gövdesiyle POST kullanın.'"""),
    ("description: Whether half-day holidays count as business days.", "description: Yarım gün tatillerin iş günü sayılıp sayılmayacağı."),
    ("description: Invalid parameter.", "description: Geçersiz parametre."),
    ("description: Not found.", "description: Bulunamadı."),
    ("description: The request is valid but cannot be fulfilled.", "description: İstek geçerli ama karşılanamıyor."),
    ("description: Too many requests. See `Retry-After`.", "description: Çok fazla istek. `Retry-After` başlığına bakın."),
    ("description: An upstream source (TCMB) could not be reached.", "description: Kaynağa (TCMB) ulaşılamadı."),
    ("description: 'Rates are TRY per this many units (e.g. 100 for JPY).'", "description: 'Kurlar bu kadar birim başına TL karşılığıdır (ör. JPY için 100).'"),
    ("description: True when a religious date is calculated rather than announced by Diyanet.",
     "description: 'Dini bayram tarihi Diyanet tarafından açıklanmamış, hesaplanmışsa true.'"),
]

src = (ROOT / "openapi.yaml").read_text(encoding="utf-8")
out = src
missing = []
for en, tr in T:
    if en not in out:
        missing.append(en.strip().splitlines()[0][:90])
        continue
    out = out.replace(en, tr)

if missing:
    sys.exit("Not found in openapi.yaml (update the translation table):\n  " + "\n  ".join(missing))

# A plain YAML scalar cannot start with a backtick: quote those values.
import re
out = re.sub(
    r"^(\s*(?:summary|description): )(`[^\n]*)$",
    lambda m: m.group(1) + "'" + m.group(2).replace("'", "''") + "'",
    out,
    flags=re.M,
)

(ROOT / "openapi.tr.yaml").write_text(out, encoding="utf-8")
print("Wrote openapi.tr.yaml")
