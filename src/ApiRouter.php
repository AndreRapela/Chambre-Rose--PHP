<?php

declare(strict_types=1);

namespace ChambreRose;

final class ApiRouter
{
    /** @param list<RouteHandler> $handlers */
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

        foreach ($this->handlers as $handler) {
            $response = $handler->handle($request);
            if ($response !== null) {
                return $response;
            }
        }

        throw new ApiException(404, 'Endpoint not found.');
    }
}
