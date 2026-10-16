<?php

declare(strict_types=1);

use ChambreRose\ApiException;
use ChambreRose\CalendarRoutes;

/** @param callable(bool,string):void $assert */
function runAppointmentValidationChecks(callable $assert): void
{
    $validate = new ReflectionMethod(CalendarRoutes::class, 'validate');
    $input = ['title' => 'Dinner', 'clientName' => 'Client',
        'startsAt' => '2026-02-28T18:00:00.000Z', 'endsAt' => '2026-02-28T20:00:00.000Z'];
    $reject = static function (array $payload, string $field) use ($assert, $validate): void {
        try {
            $validate->invoke(null, $payload);
            $assert(false, "Malformed appointment {$field} must not be normalized into a different value.");
        } catch (ApiException $error) {
            $assert($error->status === 400 && isset($error->fields[$field]),
                "Malformed appointment {$field} must return a field-level validation error.");
        }
    };
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        foreach (['startsAt', 'endsAt'] as $field) {
            foreach (['2026-02-30T18:00:00Z', '2026-04-31T18:00:00Z', '2026-02-28T24:00:00Z',
                '2026-02-28T18:00:60Z', '2026-02-28T18:00:00+02:90', 'tomorrow', '18:00',
                '2026-02-28', '2026-02-28T18:00:00', '0000-02-28T18:00:00Z', '0999-02-28T18:00:00Z',
                '1000-01-01T00:00:00+01:00', '9999-12-31T23:59:59-01:00', null, [], true, 123] as $invalid) {
                $reject(array_replace($input, [$field => $invalid]), $field);
            }
        }
        foreach (['title', 'clientName', 'location', 'notes', 'status'] as $field) {
            foreach ([['nested'], true, 123] as $invalid) $reject(array_replace($input, [$field => $invalid]), $field);
        }
        $reject(array_replace($input, ['endsAt' => $input['startsAt']]), 'endsAt');
        $reject(array_replace($input, ['endsAt' => '2026-02-28T17:00:00Z']), 'endsAt');
        $reject(array_replace($input, ['startsAt' => '2026-02-28T18:00:00.000100Z',
            'endsAt' => '2026-02-28T18:00:00.000900Z']), 'endsAt');
    } finally {
        restore_error_handler();
    }
    $assert($warnings === [], 'Malformed appointment input must not emit PHP conversion or date warnings.');
    foreach ([
        ['2028-02-29T18:00:00Z', '2028-02-29 18:00:00.000000'],
        ['2026-02-28T18:00:00+02:30', '2026-02-28 15:30:00.000000'],
        ['2026-02-28T18:00:00.123456-03:00', '2026-02-28 21:00:00.123000'],
        ['1000-01-01T00:00:00Z', '1000-01-01 00:00:00.000000'],
    ] as [$start, $expected]) {
        $valid = $validate->invoke(null, array_replace($input, ['title' => ' Dinner ', 'location' => ' ',
            'startsAt' => $start, 'endsAt' => '9999-12-31T23:59:59.999Z', 'status' => ' confirmed ']));
        $assert($valid['starts_at'] === $expected && $valid['title'] === 'Dinner'
            && $valid['location'] === null && $valid['status'] === 'CONFIRMED',
            'Valid leap dates, offsets, text normalization and database-safe precision must be retained.');
    }
}
