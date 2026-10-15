<?php

declare(strict_types=1);

use ChambreRose\ApiException;
use ChambreRose\MarketplaceService;

/** @param callable(bool, string): void $assert */
function runProfileValidationChecks(callable $assert): void
{
    $service = (new ReflectionClass(MarketplaceService::class))->newInstanceWithoutConstructor();
    $profile = [
        'displayName' => 'Profile', 'birthDate' => '1995-05-12', 'gender' => 'WOMAN',
        'locationCity' => 'Brussels', 'locationCountry' => 'Belgium',
    ];
    $user = ['address' => 'Private street', 'city' => 'Brussels', 'country' => 'Belgium'];
    $service->validateProfileInput('ESCORT', $profile, $user);
    $assert(true, 'A valid professional profile must remain accepted.');

    foreach (['displayName', 'bio', 'gender', 'birthDate', 'locationCity', 'website'] as $field) {
        foreach ([['nested'], true, 123] as $invalid) {
            try {
                $service->validateProfileInput('ESCORT', array_replace($profile, [$field => $invalid]), $user);
                $assert(false, "{$field} must reject non-string values.");
            } catch (ApiException $exception) {
                $assert($exception->status === 400 && isset($exception->fields[$field]), "{$field} must report a field-level type error.");
            }
        }
    }

    foreach (['languages', 'services', 'interests', 'contactOptions'] as $field) {
        foreach ([[['nested']], [true], [123], ['name' => 'French'], [str_repeat('x', 81)], '{"name":"French"}', '[invalid'] as $invalid) {
            try {
                $service->validateProfileInput('ESCORT', array_replace($profile, [$field => $invalid]), $user);
                $assert(false, "{$field} must reject malformed lists without silently discarding values.");
            } catch (ApiException $exception) {
                $assert($exception->status === 400 && isset($exception->fields[$field]), "{$field} must report a field-level list error.");
            }
        }
        foreach ([[], null, '', ['French', 'English'], 'French, English', '["French","English"]'] as $valid) {
            $service->validateProfileInput('ESCORT', array_replace($profile, [$field => $valid]), $user);
            $assert(true, "{$field} must preserve supported list formats.");
        }
    }

    foreach (['priceFrom', 'priceTo', 'priceHour', 'priceNight', 'priceWeekend'] as $field) {
        foreach ([null, '', 0, 99999999.99, '99999999.99'] as $valid) {
            $service->validateProfileInput('ESCORT', array_replace($profile, [$field => $valid]), $user);
            $assert(true, "{$field} must accept optional values and supported amount boundaries.");
        }
        foreach ([-0.01, 100000000, true, [], NAN, INF] as $invalid) {
            try {
                $service->validateProfileInput('ESCORT', array_replace($profile, [$field => $invalid]), $user);
                $assert(false, "{$field} must reject invalid amounts.");
            } catch (ApiException $exception) {
                $assert($exception->status === 400 && isset($exception->fields[$field]), "{$field} must report an amount error.");
            }
        }
    }
}
