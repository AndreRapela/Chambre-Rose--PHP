<?php

declare(strict_types=1);

namespace ChambreRose;

final class SeoRoutes implements RouteHandler
{
    public function __construct(private readonly SeoSitemapService $sitemap)
    {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'GET' || $request->path !== '/api/seo/sitemap.xml') {
            return null;
        }

        $xml = $this->sitemap->xml();
        $etag = ApiResponder::etag($xml);
        $cacheControl = 'public, max-age=3600, must-revalidate';
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', [
                'ETag' => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return new Response(200, $xml, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Length' => (string) strlen($xml),
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
        ]);
    }
}
