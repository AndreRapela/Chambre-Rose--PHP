<?php

declare(strict_types=1);

namespace ChambreRose;

use Closure;

final class ApiRouter
{
    /**
     * @param list<RouteHandler|(Closure(): RouteHandler)|array{0: list<string>, 1: RouteHandler|(Closure(): RouteHandler)}> $handlers
     */
    public function __construct(private readonly array $handlers)
    {
    }

    public function dispatch(Request $request): Response
    {
        if ($request->method === 'GET' && $request->path === '/api/health') {
            return ApiResponder::json([
                'status' => 'UP',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        foreach ($this->handlers as $entry) {
            if (is_array($entry)) {
                [$prefixes, $target] = $entry;
                if (!self::matchesAnyPrefix($request->path, $prefixes)) {
                    continue;
                }
                $handler = $target instanceof Closure ? $target() : $target;
            } else {
                $handler = $entry instanceof Closure ? $entry() : $entry;
            }

            $response = $handler->handle($request);
            if ($response !== null) {
                return $response;
            }
        }

        throw new ApiException(404, 'Endpoint not found.');
    }

    /** @param list<string> $prefixes */
    private static function matchesAnyPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
