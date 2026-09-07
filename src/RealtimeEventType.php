<?php

declare(strict_types=1);

namespace ChambreRose;

final class RealtimeEventType
{
    public const STREAM_READY = 'STREAM_READY';
    public const MESSAGE_CREATED = 'MESSAGE_CREATED';
    public const CONVERSATION_READ = 'CONVERSATION_READ';
    public const INBOX_UPDATED = 'INBOX_UPDATED';
    public const NOTIFICATION_CREATED = 'NOTIFICATION_CREATED';

    private function __construct()
    {
    }
}
