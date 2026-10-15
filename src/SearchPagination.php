<?php

declare(strict_types=1);

namespace ChambreRose;

final class SearchPagination
{
    /** @return array{page:int,pageSize:int,totalPages:int,offset:int} */
    public static function resolve(int $total, mixed $requestedPage, mixed $requestedSize, int $maximumSize = 50): array
    {
        $total = max(0, $total);
        $pageSize = self::pageSize($requestedSize, $maximumSize);
        $totalPages = $total === 0 ? 0 : intdiv($total - 1, $pageSize) + 1;
        $page = min(max(1, self::integer($requestedPage, 1)), max(1, $totalPages));

        return ['page' => $page, 'pageSize' => $pageSize, 'totalPages' => $totalPages, 'offset' => ($page - 1) * $pageSize];
    }

    /** @return array{page:int,pageSize:int,offset:int} */
    public static function withoutTotal(mixed $requestedPage, mixed $requestedSize, int $maximumSize = 50): array
    {
        $pageSize = self::pageSize($requestedSize, $maximumSize);
        $maximumPage = intdiv(PHP_INT_MAX, $pageSize);
        if ($maximumPage < PHP_INT_MAX) $maximumPage++;
        $page = min(max(1, self::integer($requestedPage, 1)), $maximumPage);

        return ['page' => $page, 'pageSize' => $pageSize, 'offset' => ($page - 1) * $pageSize];
    }

    private static function pageSize(mixed $requestedSize, int $maximumSize): int
    {
        return min(max(1, $maximumSize), max(1, self::integer($requestedSize, 20)));
    }

    private static function integer(mixed $value, int $default): int
    {
        if (!is_int($value) && !is_string($value)) return $default;
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? $default : $integer;
    }
}
