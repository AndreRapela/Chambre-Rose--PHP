<?php

declare(strict_types=1);

namespace ChambreRose;

final class ProductRoutes implements RouteHandler
{
    public function __construct(
        private readonly ProductService $service,
        private readonly ProductRepository $products,
        private readonly ProductImageRepository $images,
        private readonly ApiRequestGuard $guard,
        private readonly UserNotificationService $notifications
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;
        $match = [];

        if ($method === 'GET' && $path === '/api/products') {
            return ApiResponder::json($this->service->search($request->query));
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            return ApiResponder::json($this->service->get((int) $match[1]));
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)/images/(main|secondary)$#', $path, $match)) {
            return $this->image($request, (int) $match[1], strtoupper($match[2]));
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)/images/(main|secondary)/(320|640|960|1280)\.webp$#', $path, $match)) {
            return $this->responsiveImage($request, (int) $match[1], strtoupper($match[2]), (int) $match[3]);
        }
        if ($method === 'POST' && preg_match('#^/api/products/(\d+)/purchases$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $productId = (int) $match[1];
            $product = $this->products->registerPurchase($productId, (int) $user['id']);
            $storeUserId = (int) ($product['storeUserId'] ?? 0);
            if ($storeUserId > 0 && $storeUserId !== (int) $user['id']) {
                $this->notifications->notify(
                    $storeUserId,
                    UserNotificationService::MARKETPLACE,
                    'PRODUCT_PURCHASED',
                    '/catalogue/produto/' . $productId,
                    null,
                    ['productName' => (string) ($product['name'] ?? '')]
                );
            }

            return ApiResponder::json($product, 201);
        }
        if (($method === 'POST' && $path === '/api/products')
            || ($method === 'PUT' && preg_match('#^/api/products/(\d+)$#', $path, $match))) {
            return $this->save($request, $method === 'PUT' ? (int) $match[1] : null);
        }
        if ($method === 'DELETE' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            return $this->delete($request, (int) $match[1]);
        }

        return null;
    }

    private function save(Request $request, ?int $id): Response
    {
        $user = $this->guard->currentUser($request);
        if ($request->contentType() === 'multipart/form-data') {
            $form = $request->multipart();
            $product = $this->service->save(
                $id,
                (int) $user['id'],
                (string) $user['role'],
                $form['fields'],
                $form['files']['mainImage'] ?? null,
                $form['files']['secondaryImage'] ?? null
            );
        } else {
            $this->guard->requireJson($request);
            $product = $this->service->save(
                $id,
                (int) $user['id'],
                (string) $user['role'],
                $request->json(),
                null,
                null
            );
        }

        return ApiResponder::json($product, $request->method === 'POST' ? 201 : 200);
    }

    private function delete(Request $request, int $id): Response
    {
        $user = $this->guard->currentUser($request);
        $product = $this->products->find($id, false) ?? throw new ApiException(404, 'Product not found.');
        $ownsProduct = $user['role'] === 'STORE'
            && (int) ($product['storeUserId'] ?? 0) === (int) $user['id'];
        if ($user['role'] !== 'ADMIN' && !$ownsProduct) {
            throw new ApiException(403, 'Only the product store or an administrator may remove it.');
        }
        $this->products->setActive($id, false);

        return ApiResponder::empty();
    }

    private function image(Request $request, int $productId, string $role): Response
    {
        $this->service->get($productId);
        $image = $this->images->get($productId, $role);
        if ($image === null) {
            throw new ApiException(404, 'Product image not found.');
        }
        $etag = ApiResponder::etag($productId . '|' . $role . '|' . $image['size_bytes'] . '|' . $image['updated_at']);
        $cache = 'public, max-age=3600, must-revalidate';
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cache]);
        }

        return new Response(200, (string) $image['image_data'], [
            'Content-Type' => (string) $image['content_type'],
            'Content-Length' => (string) $image['size_bytes'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }

    private function responsiveImage(Request $request, int $productId, string $role, int $width): Response
    {
        $this->service->get($productId);
        $image = $this->images->responsive($productId, $role, $width);
        $etag = ApiResponder::etag($productId . '|' . $role . '|' . $image['sourceHash'] . '|' . $width);
        $cache = 'public, max-age=86400, must-revalidate';
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cache]);
        }

        return new Response(200, $image['bytes'], [
            'Content-Type' => $image['contentType'],
            'Content-Length' => (string) $image['size'],
            'Content-Disposition' => 'inline; filename="product-' . strtolower($role) . '-' . $width . '.webp"',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }
}
