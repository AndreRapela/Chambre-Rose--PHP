<?php

declare(strict_types=1);

namespace ChambreRose;

final class ApiResponder
{
    public static function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status, self::noStore());
    }

    public static function empty(int $status = 204): Response
    {
        return new Response($status, '', self::noStore());
    }

    /** @return array<string, string> */
    public static function noStore(): array
    {
        return ['Cache-Control' => 'no-store'];
    }

    public static function etag(string $value): string
    {
        return '"' . hash('sha256', $value) . '"';
    }

    public static function etagMatches(Request $request, string $etag): bool
    {
        $header = $request->header('if-none-match');
        if ($header === null) {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || preg_replace('/^W\//i', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
