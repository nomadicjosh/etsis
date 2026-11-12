<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Application\Http\Security\LoginCookieReader;
use Codefy\Framework\Http\Middleware\Auth\{AuthenticationMiddleware, UserSessionMiddleware};
use Domain\Shared\Exception\AccessDeniedException;
use Domain\User\Service\LoginSessionAuthority;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

/** Runs after successful password authentication, before the framework creates the new cookie. */
final readonly class StartLoginSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoginCookieReader $cookies,
        private LoginSessionAuthority $sessions,
        private UserSessionMiddleware $framework,
    ) {
    }
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $authenticated = $request->getAttribute(AuthenticationMiddleware::AUTH_ATTRIBUTE);
        if (
            !is_object($authenticated) ||
            !is_string($authenticated->token ?? null) ||
            $authenticated->token === ""
        ) {
            throw new AccessDeniedException("Password authentication is required before starting a sign-in.");
        }
        $this->sessions->revokeCurrent($this->cookies->read($request));
        $values = $request->getCookieParams();
        unset($values[$this->cookies->name()]);
        return $this->framework->process($request->withCookieParams($values), $handler);
    }
}
