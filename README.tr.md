# Türkiye API

**Türkiye'ye özgü, her geliştiricinin bir noktada yeniden yazdığı şeyler için açık kaynak REST API:**
döviz kurları, resmi tatiller ve iş günü hesabı, il/ilçe/mahalle ve posta kodları, TCKN/VKN/IBAN
doğrulama, Türkçe metin işlemleri, QR kod ve link önizleme.

[English README](README.md) · [API dokümantasyonu](https://lupusoft.com/api/docs/) · [OpenAPI](openapi.yaml)

```bash
curl "https://lupusoft.com/api/v1/text/number-to-words?number=1.250,50&currency=TRY"
```
```json
{ "data": { "words": "bin iki yüz elli Türk lirası elli kuruş", "invoice": "#BinİkiYüzElliTürkLirasıElliKuruş#" } }
```

## Modüller

| Modül | Ne yapar |
|---|---|
| **Döviz kurları** | TCMB gösterge kurları; 1996'ya kadar geçmiş tarih, hafta sonu ve tatilde son yayımlanan bültene düşme, çapraz kur çevirisi, JPY gibi 100 birimlik kurların doğru hesabı. |
| **Tatil ve iş günü** | 2429 sayılı Kanun'a göre tatiller; arife ve 28 Ekim yarım günleri (13:00'ten itibaren), tarihsel değişiklikler (1 Mayıs 2009'dan, 15 Temmuz 2017'den beri), yılbaşına taşan bayramlar, çakışan tatiller (ör. 19 Mayıs 2027), isteğe bağlı cumartesi mesaisi. İki tarih arası iş günü sayısı ve "X iş günü sonra hangi tarih" hesabı. |
| **Adres** | 81 il, 973 ilçe, 73.305 mahalle ve posta kodu; Türkçe karakter ve büyük/küçük harf duyarsız otomatik tamamlama (`sisli` yazınca `Şişli` bulunur); iller arası karayolu mesafesi. |
| **Doğrulama** | TCKN ve VKN algoritması, IBAN (70 ülke, Türk bankası tespiti), telefon tipi ve E.164 formatı, araç plakası. Tek istekte 100 değere kadar toplu doğrulama. Kişisel numaralar URL'ye ve loglara düşmesin diye yalnızca POST kabul eder. |
| **Metin** | Doğru `i/İ` ve `ı/I` dönüşümü, slug, fatura formatında tutarı yazıyla yazma, heceleme. |
| **QR kod** | SVG veya PNG, renk, hata düzeltme seviyesi, şeffaf arka plan. URL doğrudan `<img src>` içinde kullanılabilir. |
| **Link önizleme** | Open Graph / Twitter Card / HTML meta verisi; SSRF korumalı, 24 saat önbellekli. |

## Kurulum

PHP 7.4 – 8.4 (`pdo_sqlite`, `curl`, `mbstring`, `dom`, `simplexml`; PNG QR için `gd`) yeterli, ayrı bir veritabanı sunucusu gerekmez.

```bash
composer install
cp .env.example .env
composer setup        # SQLite veritabanını oluşturur, adres verisini yükler (~1 sn)
composer serve        # http://127.0.0.1:8080 — dokümantasyon /docs/ altında
```

### Plesk'te bir alt dizine yayınlama (ör. `lupusoft.com/api/`)

1. `sh tools/build-upload.sh` ile `dist/api` klasörünü oluşturun (vendor dahil).
2. İçeriğini `httpdocs/api/` dizinine yükleyin. Başka bir şey gerekmez: `.env` ve SSH zorunlu değil.
   Adres verisi ilk istekte otomatik yüklenir, gizli anahtar `app/storage/` içine üretilir.
3. `app/` dizini (kod, veri, SQLite) `.htaccess` ile dışarıya kapalıdır; sunucunun Apache
   (veya Plesk'teki varsayılan nginx + Apache) olması gerekir.

İsteğe bağlı zamanlanmış görev: `45 15,16 * * 1-5  php /yol/api/app/bin/fetch-rates.php`
(kurlar zaten ilk istekte çekilir). Geçmiş kurlar için: `php bin/fetch-rates.php --from=2025-01-01`.

### Yönetim

```bash
php bin/api-key.php create --name="Firma" --limit=1200   # anahtar yalnızca bir kez gösterilir
php bin/stats.php --days=30                              # gün ve uç nokta bazında istek sayıları
```

## Önemli not

Doğrulamalar yalnızca algoritmiktir. Geçerli bir TCKN sağlaması, numaranın gerçek bir kişiye ait
olduğu anlamına gelmez; bu API hiçbir kamu sistemini sorgulamaz.

## Veri kaynakları

- Kurlar: TCMB gösterge niteliğindeki döviz kurları.
- Adres, posta kodu ve mesafeler: Murat Gözel'in [turkey-neighbourhoods](https://github.com/muratgozel/turkey-neighbourhoods) paketi (MIT).
- Tatiller: 2429 sayılı Kanun; dini bayram tarihleri Diyanet İşleri Başkanlığı takvimine göre. Diyanet'in henüz açıklamadığı yıllar Ümmü'l-Kurâ takvimiyle hesaplanır ve `estimated: true` olarak işaretlenir.

## Lisans

[MIT](LICENSE)
