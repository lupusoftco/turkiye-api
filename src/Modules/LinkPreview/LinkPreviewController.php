<?php

declare(strict_types=1);

namespace TurkiyeApi\Modules\LinkPreview;

use TurkiyeApi\Http\ApiException;
use TurkiyeApi\Http\Request;
use TurkiyeApi\Http\Response;
use TurkiyeApi\Storage\Cache;

final class LinkPreviewController
{
    private const TTL = 86400;

    private SafeHttpClient $http;
    private Cache $cache;

    public function __construct(SafeHttpClient $http, Cache $cache)
    {
        $this->http = $http;
        $this->cache = $cache;
    }

    public function preview(Request $request): Response
    {
        $url = trim($request->required('url'));
        if (strlen($url) > 2048) {
            throw ApiException::badRequest('`url` can be at most 2048 characters.', 'url');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $url = (string) preg_replace('/#.*$/', '', $url);
        $refresh = $request->bool('refresh', false);

        $key = 'preview:' . sha1($url);
        $cached = $refresh ? null : $this->cache->get($key);
        if (is_array($cached)) {
            return Response::data($cached, ['cached' => true], 3600);
        }

        $page = $this->http->get($url);
        if ($page['status'] >= 400) {
            throw new ApiException(422, 'fetch-failed', "The site answered with HTTP {$page['status']}.", ['upstream_status' => $page['status']]);
        }
        $type = strtolower($page['content_type']);
        if ($type !== '' && !str_contains($type, 'html')) {
            $data = [
                'url' => $page['url'],
                'title' => null,
                'description' => null,
                'site_name' => null,
                'image' => str_starts_with($type, 'image/') ? $page['url'] : null,
                'image_alt' => null,
                'favicon' => UrlResolver::resolve($page['url'], '/favicon.ico'),
                'type' => strtok($type, ';') ?: null,
                'locale' => null,
                'theme_color' => null,
                'canonical' => null,
            ];
        } else {
            $data = MetadataExtractor::extract($page['body'], $page['url'], $page['content_type']);
        }
        $data = ['requested_url' => $url] + $data;
        $this->cache->set($key, $data, self::TTL);

        return Response::data($data, ['cached' => false], 3600);
    }
}
