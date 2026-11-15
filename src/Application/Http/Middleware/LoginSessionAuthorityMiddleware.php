<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Domain\User\Service\LoginSessionAuthority;
use Domain\Shared\Exception\AuthenticationRequiredException;
use Application\Http\Security\LoginCookieReader;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

final readonly class LoginSessionAuthorityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoginCookieReader $cookies,
        private LoginSessionAuthority $sessions,
    ) {
    }
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (!SisPrivacyMiddleware::protects($request)) {
            return $handler->handle($request);
        }
        $proof = $this->cookies->read($request);
        $valid = $proof !== null && $this->sessions->permits($proof);
        if (
            ($proof === null && array_key_exists($this->cookies->name(), $request->getCookieParams())) ||
            ($proof !== null && !$valid)
        ) {
            $values = $request->getCookieParams();
            unset($values[$this->cookies->name()]);
            // A subsequent password sign-in must issue a fresh envelope, not reuse a revoked cookie.
            $request = $request
                ->withoutAttribute(UserCookieDecryptMiddleware::USER_COOKIE)
                ->withCookieParams($values);
        }
        // The signed Moodle server channel is handled by the outer channel middleware.
        // Check workspace expiry before CSRF so an expired form cannot masquerade as denied access.
        if (!$valid && preg_match('~(?:^|/)sis(?:/|$)~i', rawurldecode($request->getUri()->getPath())) === 1) {
            throw new AuthenticationRequiredException('Authentication required.');
        }
        return $handler->handle($request);
    }
}
