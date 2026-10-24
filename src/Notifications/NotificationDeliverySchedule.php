<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class NotificationDeliverySchedule
{
    public static function nextDailyDigest(
        string $time,
        string $timezone,
        ?DateTimeImmutable $now = null
    ): string {
        $localNow = self::localNow($timezone, $now);
        [$hour, $minute] = self::time($time, '09:00');
        $delivery = $localNow->setTime($hour, $minute);
        if ($delivery <= $localNow) {
            $delivery = $delivery->modify('+1 day');
        }

        return self::utc($delivery);
    }

    public static function afterQuietHours(
        bool $enabled,
        string $start,
        string $end,
        string $timezone,
        ?DateTimeImmutable $now = null
    ): ?string {
        if (!$enabled || $start === $end) {
            return null;
        }

        $localNow = self::localNow($timezone, $now);
        [$startHour, $startMinute] = self::time($start, '22:00');
        [$endHour, $endMinute] = self::time($end, '08:00');
        $startToday = $localNow->setTime($startHour, $startMinute);
        $endToday = $localNow->setTime($endHour, $endMinute);

        if ($startToday < $endToday) {
            return $localNow >= $startToday && $localNow < $endToday
                ? self::utc($endToday)
                : null;
        }

        if ($localNow >= $startToday) {
            return self::utc($endToday->modify('+1 day'));
        }
        if ($localNow < $endToday) {
            return self::utc($endToday);
        }

        return null;
    }

    private static function localNow(string $timezone, ?DateTimeImmutable $now): DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone);
    }

    /** @return array{int, int} */
    private static function time(string $time, string $fallback): array
    {
        $normalized = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : $fallback;
        [$hour, $minute] = array_map('intval', explode(':', $normalized));

        return [$hour, $minute];
    }

    private static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
