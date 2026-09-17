<?php

declare(strict_types=1);

namespace ChambreRose;

final class SearchPagination
{
    /** @return array{page:int,pageSize:int,totalPages:int,offset:int} */
    public static function resolve(int $total, mixed $requestedPage, mixed $requestedSize, int $maximumSize = 50): array
    {
        $pageSize = min($maximumSize, max(1, (int) ($requestedSize ?? 20)));
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $pageSize);
        $page = min(max(1, (int) ($requestedPage ?? 1)), max(1, $totalPages));

        return ['page' => $page, 'pageSize' => $pageSize, 'totalPages' => $totalPages, 'offset' => ($page - 1) * $pageSize];
    }
}
