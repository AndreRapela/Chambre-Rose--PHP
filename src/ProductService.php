<?php

declare(strict_types=1);

namespace ChambreRose;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductImageRepository $images,
        private readonly UserRepository $users
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(array $filters): array
    {
        return $this->products->search($filters);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->products->find($id) ?? throw new ApiException(404, 'Product not found.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(?int $id, int $actorId, string $actorRole, array $input, ?UploadedFile $main, ?UploadedFile $secondary): array
    {
        $existing = $id === null ? null : $this->products->find($id, false);
        if ($id !== null && $existing === null) {
            throw new ApiException(404, 'Product not found.');
        }
        if ($actorRole !== 'ADMIN') {
            if ($actorRole !== 'STORE' || ($existing !== null && (int) ($existing['storeUserId'] ?? 0) !== $actorId)) {
                throw new ApiException(403, 'Only the product store or an administrator may edit it.');
            }
        }
        $store = $actorRole === 'STORE' ? $this->users->find($actorId) : null;
        $data = $this->validate(array_replace($existing ?? [], $input));
        if ($actorRole === 'STORE') {
            $data['storeUserId'] = $actorId;
            $data['storeName'] = $data['storeName'] ?: trim(($store['firstName'] ?? '') . ' ' . ($store['lastName'] ?? ''));
            $data['storeCity'] = $data['storeCity'] ?: ($store['city'] ?? null);
        }
        if ($id === null && ($main === null || $main->isEmpty()) && trim((string) $data['imageUrl']) === '') {
            throw new ApiException(400, 'A main product image is required.', ['mainImage' => 'is required']);
        }
        $product = $id === null ? $this->products->create($data) : $this->products->update($id, $data);
        foreach (['MAIN' => $main, 'SECONDARY' => $secondary] as $role => $file) {
            if ($file !== null && !$file->isEmpty()) {
                $this->images->put((int) $product['id'], $role, $file);
                $field = $role === 'MAIN' ? 'imageUrl' : 'secondaryImageUrl';
                $data[$field] = '/api/products/' . $product['id'] . '/images/' . strtolower($role);
            }
        }
        if (($data['imageUrl'] ?? '') !== $product['imageUrl'] || ($data['secondaryImageUrl'] ?? null) !== $product['secondaryImageUrl']) {
            $product = $this->products->update((int) $product['id'], $data);
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $errors = [];
        $limits = [
            'name' => 120, 'category' => 60, 'imageUrl' => 500, 'secondaryImageUrl' => 500,
            'tag' => 40, 'saleLabel' => 40, 'description' => 3000, 'storeName' => 120,
            'storeAddress' => 160, 'storeCity' => 80, 'storeSegment' => 80, 'storeHours' => 120,
            'productType' => 80, 'material' => 160, 'availableSizes' => 120, 'colorOptions' => 120,
            'stockStatus' => 80, 'shippingNote' => 160, 'careInstructions' => 160,
        ];
        $data = [];
        foreach ($limits as $field => $max) {
            $value = trim((string) ($input[$field] ?? ''));
            if (($field === 'name' || $field === 'category') && $value === '') {
                $errors[$field] = 'is required';
            } elseif (self::length($value) > $max) {
                $errors[$field] = "cannot exceed {$max} characters";
            }
            $data[$field] = $value === '' && !in_array($field, ['name', 'category', 'imageUrl'], true) ? null : $value;
        }
        foreach (['price', 'originalPrice'] as $field) {
            $value = $input[$field] ?? null;
            if ($field === 'price' && ($value === null || $value === '')) {
                $errors[$field] = 'is required';
            } elseif ($value !== null && $value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 99999999.99)) {
                $errors[$field] = 'must be a valid non-negative amount';
            }
            $data[$field] = $value === null || $value === '' ? null : (float) $value;
        }
        $data += [
            'storeUserId' => isset($input['storeUserId']) ? (int) $input['storeUserId'] : null,
            'reviews' => max(0, (int) ($input['reviews'] ?? 0)),
            'purchaseCount' => max(0, (int) ($input['purchaseCount'] ?? 0)),
            'likes' => max(0, (int) ($input['likes'] ?? 0)),
            'active' => !array_key_exists('active', $input) || filter_var($input['active'], FILTER_VALIDATE_BOOL),
        ];
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid product data.', $errors);
        }

        return $data;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
