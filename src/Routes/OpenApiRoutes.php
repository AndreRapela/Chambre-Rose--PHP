<?php

declare(strict_types=1);

namespace ChambreRose;

final class OpenApiRoutes implements RouteHandler
{
    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'GET') {
            return null;
        }

        if ($request->path === '/api/openapi.json' || $request->path === '/api/docs/openapi.json') {
            return $this->specResponse();
        }

        if ($request->path === '/api/docs' || $request->path === '/api/swagger') {
            return $this->swaggerUiResponse();
        }

        return null;
    }

    private function specResponse(): Response
    {
        $path = dirname(__DIR__, 2) . '/resources/openapi/openapi.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new ApiException(404, 'OpenAPI specification not found.');
        }

        return new Response(200, $json, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    private function swaggerUiResponse(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chambre Rose — REST API Explorer (Swagger)</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.18.2/swagger-ui.css">
  <style>
    body {
      margin: 0;
      background: #fff8f5;
      color: #2a181a;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .cr-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.85rem 1.75rem;
      background: linear-gradient(135deg, #5e0421 0%, #8c0434 55%, #420317 100%);
      border-bottom: 1px solid rgba(235, 190, 102, 0.5);
      color: #fffdf9;
      box-shadow: 0 6px 20px rgba(140, 4, 52, 0.22);
    }
    .cr-brand {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .cr-brand img {
      width: 2.2rem;
      height: 2.5rem;
      object-fit: contain;
      background: #fff8f5;
      border-radius: 8px;
      padding: 0.15rem;
    }
    .cr-brand strong {
      display: block;
      font-size: 1.15rem;
      letter-spacing: 0.02em;
    }
    .cr-brand small {
      color: #f7d486;
      font-size: 0.8rem;
      font-weight: 600;
    }
    .cr-links {
      display: flex;
      gap: 0.65rem;
    }
    .cr-links a {
      padding: 0.45rem 0.85rem;
      border-radius: 999px;
      border: 1px solid rgba(247, 212, 134, 0.55);
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .cr-links a:hover {
      background: rgba(255, 255, 255, 0.2);
    }
    .swagger-ui .topbar { display: none; }
    .swagger-ui .info .title { color: #8c0434; }
    .swagger-ui .btn.authorize {
      border-color: #8c0434;
      color: #8c0434;
    }
    .swagger-ui .btn.execute {
      background-color: #b8143a;
      border-color: #8c0434;
    }
  </style>
</head>
<body>
  <header class="cr-topbar">
    <div class="cr-brand">
      <img src="/api/brand/logo?format=png" alt="Chambre Rose">
      <div>
        <strong>Chambre Rose — REST API</strong>
        <small>OpenAPI 3.1 Interactive Documentation</small>
      </div>
    </div>
    <nav class="cr-links">
      <a href="/api/openapi.json" target="_blank" rel="noopener">OpenAPI JSON</a>
      <a href="http://localhost:4200" target="_blank" rel="noopener">Web Application</a>
    </nav>
  </header>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.18.2/swagger-ui-bundle.js"></script>
  <script>
    window.onload = function () {
      window.ui = SwaggerUIBundle({
        url: '/api/openapi.json',
        dom_id: '#swagger-ui',
        deepLinking: true,
        persistAuthorization: true,
        displayRequestDuration: true,
        filter: true,
        tryItOutEnabled: true
      });
    };
  </script>
</body>
</html>
HTML;

        return new Response(200, $html, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Content-Security-Policy' => "default-src 'self' https://unpkg.com; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: https:; connect-src 'self' http://localhost:8088 http://localhost:4200; frame-ancestors 'none'",
        ]);
    }
}

