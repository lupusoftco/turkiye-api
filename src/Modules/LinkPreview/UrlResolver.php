<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\LinkPreview;

/**
 * Resolves a relative reference against a base URL (RFC 3986, section 5.2).
 */
final class UrlResolver
{
    public static function resolve(string $base, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        if (str_starts_with($ref, '//')) {
            return "$scheme:$ref";
        }
        $authority = ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        $origin = "$scheme://$authority";

        if (str_starts_with($ref, '#')) {
            return strtok($base, '#') . $ref;
        }
        if (str_starts_with($ref, '?')) {
            return $origin . ($b['path'] ?? '/') . $ref;
        }
        if (str_starts_with($ref, '/')) {
            $path = $ref;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/') ?? '/';
            $path = ($dir === '' ? '/' : $dir) . $ref;
        }

        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $normalized = implode('/', $out);
        if (preg_match('#/\.\.?$#', $path)) {
            $normalized .= '/';
        }
        return $origin . (str_starts_with($normalized, '/') ? '' : '/') . $normalized . $query;
    }
}
