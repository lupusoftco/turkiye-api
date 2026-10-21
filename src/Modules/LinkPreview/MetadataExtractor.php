<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\LinkPreview;

/**
 * Extracts Open Graph, Twitter Card and basic HTML metadata from a page.
 */
final class MetadataExtractor
{
    /**
     * @return array{url: string, title: ?string, description: ?string, site_name: ?string, image: ?string, image_alt: ?string, favicon: string, type: ?string, locale: ?string, theme_color: ?string, canonical: ?string}
     */
    public static function extract(string $html, string $pageUrl, string $contentType = ''): array
    {
        $html = self::toUtf8($html, $contentType);

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);

        $base = $pageUrl;
        $baseHref = self::attr($xpath, '//base[@href]', 'href');
        if ($baseHref !== null) {
            $base = UrlResolver::resolve($pageUrl, $baseHref);
        }

        $meta = [];
        foreach ($xpath->query('//meta') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name') ?: $node->getAttribute('itemprop')));
            $content = trim($node->getAttribute('content'));
            if ($key !== '' && $content !== '' && !isset($meta[$key])) {
                $meta[$key] = $content;
            }
        }

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? null;
        if ($title === null) {
            $titleNode = self::first($xpath, '//title');
            $title = $titleNode !== null ? trim($titleNode->textContent) : null;
        }

        $image = $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? $meta['twitter:image:src'] ?? $meta['image'] ?? null;

        $favicon = null;
        foreach (['icon', 'shortcut icon', 'apple-touch-icon'] as $rel) {
            $favicon = self::attr($xpath, sprintf(
                "//link[translate(@rel,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='%s'][@href]",
                $rel,
            ), 'href');
            if ($favicon !== null) {
                break;
            }
        }

        $canonical = self::attr($xpath, "//link[translate(@rel,'CANONICAL','canonical')='canonical'][@href]", 'href');

        return [
            'url' => $pageUrl,
            'title' => self::clean($title, 300),
            'description' => self::clean($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? null, 1000),
            'site_name' => self::clean($meta['og:site_name'] ?? $meta['application-name'] ?? null, 200),
            'image' => $image !== null ? self::absolute($base, $image) : null,
            'image_alt' => self::clean($meta['og:image:alt'] ?? $meta['twitter:image:alt'] ?? null, 300),
            'favicon' => $favicon !== null ? (string) self::absolute($base, $favicon) : UrlResolver::resolve($pageUrl, '/favicon.ico'),
            'type' => self::clean($meta['og:type'] ?? null, 50),
            'locale' => self::clean($meta['og:locale'] ?? null, 20),
            'theme_color' => self::clean($meta['theme-color'] ?? null, 30),
            'canonical' => $canonical !== null ? self::absolute($base, $canonical) : null,
        ];
    }

    private static function attr(\DOMXPath $xpath, string $query, string $attr): ?string
    {
        $node = self::first($xpath, $query);
        if (!$node instanceof \DOMElement) {
            return null;
        }
        $value = trim($node->getAttribute($attr));
        return $value === '' ? null : $value;
    }

    private static function first(\DOMXPath $xpath, string $query): ?\DOMNode
    {
        $list = $xpath->query($query);
        return $list === false ? null : $list->item(0);
    }

    private static function absolute(string $base, string $url): ?string
    {
        $resolved = UrlResolver::resolve($base, html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return preg_match('#^https?://#i', $resolved) ? $resolved : null;
    }

    private static function clean(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    private static function toUtf8(string $html, string $contentType): string
    {
        $charset = null;
        if (preg_match('/charset=["\']?([\w\-]+)/i', $contentType, $m)) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }
        $charset = $charset !== null ? strtoupper($charset) : null;
        if ($charset !== null && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            // Unknown charset names warn (PHP 7) or throw (PHP 8); fall through to detection.
            try {
                $converted = (string) @mb_convert_encoding($html, 'UTF-8', $charset);
                if ($converted !== '') {
                    return $converted;
                }
            } catch (\Throwable $e) {
            }
        }
        return mb_check_encoding($html, 'UTF-8') ? $html : (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1254');
    }
}
