<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Codefy\Framework\Http\Middleware\Auth\UserCookieDecryptMiddleware;
use Domain\User\Service\LoginSessionAuthority;
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
        if (
            ($proof === null && array_key_exists($this->cookies->name(), $request->getCookieParams())) ||
            ($proof !== null && !$this->sessions->permits($proof))
        ) {
            $values = $request->getCookieParams();
            unset($values[$this->cookies->name()]);
            // A subsequent password sign-in must issue a fresh envelope, not reuse a revoked cookie.
            $request = $request
                ->withoutAttribute(UserCookieDecryptMiddleware::USER_COOKIE)
                ->withCookieParams($values);
        }
        return $handler->handle($request);
    }
}
