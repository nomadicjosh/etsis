<?php

declare(strict_types=1);

namespace Application\Http\Response;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\View\Native\NativeLoader;
use Application\Http\Security\ApplicationContinuation;

use function Codefy\Framework\Helpers\trans;

final class RegistrationPauseResponse
{
    public static function make(
        ServerRequestInterface $request,
        int $status,
        int $retryAfter,
        string $loginPath,
    ): ResponseInterface {
        $limited = $status === 429;
        $status = $limited ? 429 : 503;
        $retryAfter = max(1, min(86400, $retryAfter));
        // Navigation is deployment-owned and same-origin, even if configuration is malformed.
        if (preg_match('~^/(?:[a-zA-Z0-9_-]+/)+$~D', $loginPath) !== 1) {
            $loginPath = '/login/';
        }
        $values = $request->getMethod() === 'POST' ? $request->getParsedBody() : $request->getQueryParams();
        $selection = ApplicationContinuation::selection(is_array($values) ? $values : []);
        $registerPath = '/register/';
        if ($selection !== []) {
            $loginPath .= ApplicationContinuation::query(['continue' => 'apply'] + $selection);
            $registerPath .= '?' . http_build_query($selection);
        }
        $message = $limited
        ? trans(
            'Too many account creation attempts. Please wait before trying again. '
            . 'You can still sign in if you already have a campus account.',
        )
        : trans(
            'Account creation is temporarily unavailable. Please wait before trying again. '
            . 'You can still sign in if you already have a campus account.',
        );
        $headers = [
            'Retry-After' => (string) $retryAfter, 'Cache-Control' => 'private, no-store, max-age=0',
            'Vary' => 'Accept', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; script-src 'none'; style-src 'self'; "
                . "img-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ];
        if (!PrivateErrorResponse::acceptsHtml($request)) {
            return new JsonResponse(['error' => $message, 'retry_after' => $retryAfter], $status, $headers);
        }
        $loader = new NativeLoader(['framework' => dirname(__DIR__, 4) . '/resources/views']);
        return new HtmlResponse($loader->render('framework::backend/errors/registration', [
            'limited' => $limited, 'message' => $message, 'retryAfter' => $retryAfter, 'loginPath' => $loginPath,
            'registerPath' => $registerPath,
        ]), $status, $headers);
    }
}
