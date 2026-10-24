<?php

declare(strict_types=1);

namespace ChambreRose;

use JsonException;

final class RealtimeRoutes implements RouteHandler
{
    public function __construct(
        private readonly RealtimeEventRepository $events,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'GET' || $request->path !== '/api/events') {
            return null;
        }
        if (PHP_SAPI === 'cli-server' && !Config::bool('REALTIME_ALLOW_CLI_SERVER', false)) {
            throw new ApiException(
                503,
                'Realtime streaming requires a concurrent web server.',
                [],
                ['Retry-After' => '10']
            );
        }

        $user = $this->guard->currentUser($request);
        $userId = (int) $user['id'];
        $cursor = self::cursor($request);
        $initialConnection = $cursor === null;
        if ($cursor === null) {
            $cursor = $this->events->latestId($userId);
        }

        return Response::stream(function () use ($userId, $cursor, $initialConnection): void {
            $this->stream($userId, $cursor, $initialConnection);
        }, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param array{id: int, type: string, resourceId: int|null, payload: array<string, mixed>, createdAt: string} $event */
    public static function encode(array $event): string
    {
        try {
            $data = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '';
        }

        return 'id: ' . $event['id'] . "\n" . 'data: ' . $data . "\n\n";
    }

    private function stream(int $userId, int $cursor, bool $initialConnection): void
    {
        ignore_user_abort(true);
        set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        $durationSeconds = min(55, max(5, Config::int('REALTIME_STREAM_SECONDS', 25)));
        $pollMilliseconds = min(2000, max(250, Config::int('REALTIME_STREAM_POLL_MS', 750)));
        $deadline = microtime(true) + $durationSeconds;
        $nextHeartbeat = microtime(true) + 10;
        echo "retry: 1500\n\n";
        if ($initialConnection) {
            echo self::encode([
                'id' => $cursor,
                'type' => RealtimeEventType::STREAM_READY,
                'resourceId' => null,
                'payload' => [],
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
        self::flushOutput();

        do {
            $events = $this->events->after($userId, $cursor);
            foreach ($events as $event) {
                $encoded = self::encode($event);
                if ($encoded !== '') {
                    echo $encoded;
                }
                $cursor = $event['id'];
            }
            if ($events !== []) {
                self::flushOutput();
            }

            $now = microtime(true);
            if ($now >= $nextHeartbeat) {
                echo ': heartbeat ' . time() . "\n\n";
                self::flushOutput();
                $nextHeartbeat = $now + 10;
            }
            if ($now >= $deadline || connection_aborted()) {
                break;
            }
            usleep($pollMilliseconds * 1000);
        } while (true);
    }

    private static function cursor(Request $request): ?int
    {
        $candidate = $request->query['cursor'] ?? $request->header('last-event-id');

        return is_scalar($candidate)
            && filter_var($candidate, FILTER_VALIDATE_INT) !== false
            && (int) $candidate >= 0
                ? (int) $candidate
                : null;
    }

    private static function flushOutput(): void
    {
        flush();
    }
}
