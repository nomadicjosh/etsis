<?php

declare(strict_types=1);

namespace Application\Http\Security;

/** Authentication entry points remain protected by their ordinary rate limits, cookie and CSRF middleware. */
final class MaintenanceRoutes
{
    public static function authentication(string $method, string $path, string $loginRoute): bool
    {
        $path = rawurldecode($path);
        $method = strtoupper($method);
        return ($path === '/' . trim($loginRoute, '/') . '/' && in_array($method, ['GET', 'HEAD'], true))
        || ($path === '/auth/' && $method === 'POST')
        || ($path === '/logout/' && in_array($method, ['GET', 'HEAD', 'POST'], true));
    }

    public static function browserCandidate(string $path, mixed $cookie): bool
    {
        // A cookie never permits external ingress to skip the normal maintenance gate.
        return !in_array(rawurldecode($path), ['/portal/payments/webhook/', '/sis/moodle/session-channel/'], true)
        && is_string($cookie) && $cookie !== '' && strlen($cookie) <= 16384;
    }
}
