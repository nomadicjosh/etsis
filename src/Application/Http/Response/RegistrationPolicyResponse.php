<?php

declare(strict_types=1);

namespace Application\Http\Response;

use Application\Http\Security\ApplicationContinuation;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\View\Native\NativeLoader;

use function Codefy\Framework\Helpers\trans;

/** Fixed policy outcomes. No submitted email, password, name, exception or configuration body. */
final class RegistrationPolicyResponse
{
    public static function make(
        ServerRequestInterface $request,
        string $reason,
        string $loginPath,
    ): ResponseInterface {
        [$status, $message] = match ($reason) {
            'closed' => [403, trans(
                'Public account creation is closed. Contact the institution if you need a campus account. '
                . 'You can still sign in to an existing account.'
            )],
            'domain' => [422, trans(
                'Use an email address from one of the domains accepted by this institution. '
                . 'Return to account creation to review the available domains.'
            )],
            default => [503, trans(
                'Account creation is temporarily unavailable. Please try again later. '
                . 'You can still sign in to an existing account.'
            )],
        };
        if (preg_match('~^/(?:[a-zA-Z0-9_-]+/)+$~D', $loginPath) !== 1) {
            $loginPath = '/login/';
        }
        $values = $request->getMethod() === 'POST' ? $request->getParsedBody() : $request->getQueryParams();
        $selection = ApplicationContinuation::selection(is_array($values) ? $values : []);
        $loginPath .= ApplicationContinuation::query(['continue' => 'apply'] + $selection);
        $registerPath = '/register/' . ($selection === [] ? '' : '?' . http_build_query($selection));
        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0', 'Vary' => 'Accept',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; script-src 'none'; style-src 'self'; "
                . "img-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ];
        if ($status === 503) {
            $headers['Retry-After'] = '5';
        }
        if (!PrivateErrorResponse::acceptsHtml($request)) {
            return new JsonResponse(['error' => $message, 'code' => match ($status) {
                403 => 'public_registration_closed', 422 => 'registration_email_domain',
                default => 'public_registration_unavailable',
            }], $status, $headers);
        }
        $loader = new NativeLoader(['framework' => dirname(__DIR__, 4) . '/resources/views']);
        return new HtmlResponse($loader->render('framework::backend/errors/registration-policy', [
            'closed' => $status === 403, 'message' => $message,
            'loginPath' => $loginPath, 'registerPath' => $registerPath,
        ]), $status, $headers);
    }
}
