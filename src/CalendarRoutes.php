<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;

final class CalendarRoutes implements RouteHandler
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->path === '/api/calendar/appointments' && $request->method === 'GET') {
            $user = $this->escort($request);

            return ApiResponder::json(['items' => $this->appointments->listFor((int) $user['id'])]);
        }
        if ($request->path === '/api/calendar/appointments' && $request->method === 'POST') {
            $user = $this->escort($request);
            $this->guard->requireJson($request);

            return ApiResponder::json(
                $this->appointments->create((int) $user['id'], self::validate($request->json())),
                201
            );
        }
        if (preg_match('#^/api/calendar/appointments/(\d+)$#', $request->path, $match)) {
            $user = $this->escort($request);
            $id = (int) $match[1];
            if ($request->method === 'PUT') {
                $this->guard->requireJson($request);

                return ApiResponder::json(
                    $this->appointments->update($id, (int) $user['id'], self::validate($request->json()))
                );
            }
            if ($request->method === 'DELETE') {
                $this->appointments->delete($id, (int) $user['id']);

                return ApiResponder::empty();
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function escort(Request $request): array
    {
        $user = $this->guard->currentUser($request);
        if (($user['role'] ?? '') !== 'ESCORT') {
            throw new ApiException(403, 'The appointment calendar is available only to companion profiles.');
        }

        return $user;
    }

    /** @param array<string,mixed> $input
     *  @return array<string,string|null>
     */
    private static function validate(array $input): array
    {
        $title = self::optionalText($input['title'] ?? null, 'title') ?? '';
        $clientName = self::optionalText($input['clientName'] ?? null, 'clientName') ?? '';
        $location = self::optionalText($input['location'] ?? null, 'location');
        $notes = self::optionalText($input['notes'] ?? null, 'notes');
        $status = strtoupper(self::optionalText($input['status'] ?? 'SCHEDULED', 'status') ?? '');
        $startsAt = self::date($input['startsAt'] ?? null, 'startsAt');
        $endsAt = self::date($input['endsAt'] ?? null, 'endsAt');
        $errors = [];
        if ($title === '' || self::length($title) > 160) $errors['title'] = 'must contain between 1 and 160 characters';
        if ($clientName === '' || self::length($clientName) > 120) $errors['clientName'] = 'must contain between 1 and 120 characters';
        if ($location !== null && self::length($location) > 200) $errors['location'] = 'cannot exceed 200 characters';
        if ($notes !== null && self::length($notes) > 2000) $errors['notes'] = 'cannot exceed 2000 characters';
        if (!in_array($status, ['SCHEDULED', 'CONFIRMED', 'COMPLETED', 'CANCELLED'], true)) {
            $errors['status'] = 'contains an unsupported value';
        }
        if ($endsAt <= $startsAt) $errors['endsAt'] = 'must be after startsAt';
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid appointment data.', $errors);
        }

        return [
            'title' => $title,
            'client_name' => $clientName,
            'starts_at' => $startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'ends_at' => $endsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'location' => $location,
            'notes' => $notes,
            'status' => $status,
        ];
    }

    private static function date(mixed $value, string $field): DateTimeImmutable
    {
        try {
            if (!is_string($value) || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/iD',
                $value
            ) !== 1) throw new \RuntimeException();
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \RuntimeException();
            }
            $date = $date->setTimezone(new DateTimeZone('UTC'));
            $year = (int) $date->format('Y');
            if ($year < 1000 || $year > 9999) throw new \RuntimeException();

            // Compare and store the same millisecond precision exposed by the API.
            return $date->setTime((int) $date->format('H'), (int) $date->format('i'),
                (int) $date->format('s'), intdiv((int) $date->format('u'), 1000) * 1000);
        } catch (\Throwable) {
            throw new ApiException(400, 'Invalid appointment data.', [$field => 'must be a valid date and time']);
        }
    }

    private static function optionalText(mixed $value, string $field): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) {
            throw new ApiException(400, 'Invalid appointment data.', [$field => 'must be text']);
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
