<?php

declare(strict_types=1);

use ChambreRose\UserRepository;

/**
 * @param callable(bool,string):void $assert
 * @param callable $request
 * @param callable $binaryRequest
 * @param array<string,mixed> $appointment
 * @param array{owner:string,visitor:string,admin:string} $sessions
 */
function runCalendarAccessHttpChecks(
    callable $assert,
    callable $request,
    callable $binaryRequest,
    PDO $pdo,
    string $peerEmail,
    array $appointment,
    array $sessions
): void {
    $path = '/api/calendar/appointments';
    $appointmentId = (int) $appointment['id'];
    [$beforeStatus, $before] = $request('GET', $path, null, $sessions['owner']);
    $assert($beforeStatus === 200, 'The calendar owner must retain access to their appointments.');
    foreach ([null, $sessions['visitor'], $sessions['admin']] as $session) {
        [$status] = $request('GET', $path, null, $session);
        $assert($status === ($session === null ? 401 : 403),
            'Anonymous, visitor and administrator sessions must not read a companion calendar.');
    }
    [$csrfStatus] = $binaryRequest('DELETE', "{$path}/{$appointmentId}", ['Cookie: ' . $sessions['owner']]);
    $assert($csrfStatus === 403, 'Cookie-backed calendar deletion must require the browser mutation header.');

    $password = 'Calendar9!check';
    $peer = (new UserRepository($pdo))->create([
        'email' => $peerEmail, 'firstName' => 'Calendar', 'lastName' => 'Integration',
        'phone' => '12345678', 'address' => 'Test address', 'city' => 'Brussels',
        'country' => 'Belgium', 'postalCode' => '1000',
    ], password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'ESCORT');
    [$loginStatus, , $peerCookie] = $request('POST', '/api/auth/login', ['email' => $peerEmail, 'password' => $password]);
    $assert($loginStatus === 200 && is_string($peerCookie), 'The second companion must have an independent session.');
    [$peerListStatus, $peerList] = $request('GET', $path, null, $peerCookie);
    $assert($peerListStatus === 200 && ($peerList['items'] ?? null) === [],
        'A second companion must not receive the owner’s private appointments.');
    $payload = array_replace($appointment, ['title' => 'Cross-account attempt']);
    [$updateStatus] = $request('PUT', "{$path}/{$appointmentId}", $payload, $peerCookie);
    [$deleteStatus] = $request('DELETE', "{$path}/{$appointmentId}", null, $peerCookie);
    $assert($updateStatus === 404 && $deleteStatus === 404,
        'A companion must not update or delete another companion’s appointment.');
    [$createStatus, $peerAppointment] = $request('POST', $path, $payload, $peerCookie);
    $assert($createStatus === 201 && (int) ($peerAppointment['escortUserId'] ?? 0) === (int) $peer['id'],
        'An owner ID supplied in JSON must not override the authenticated calendar owner.');
    [$afterStatus, $after] = $request('GET', $path, null, $sessions['owner']);
    $assert($afterStatus === 200 && $after === $before,
        'Denied mutations and spoofed ownership must leave the original calendar unchanged.');
    [$peerDeleteStatus] = $request('DELETE', $path . '/' . (int) $peerAppointment['id'], null, $peerCookie);
    $assert($peerDeleteStatus === 204, 'The second companion must be able to remove only their own appointment.');
}
