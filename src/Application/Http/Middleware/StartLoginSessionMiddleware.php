<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Application\Http\Security\LoginCookieReader;
use Codefy\Framework\Http\Middleware\Auth\{AuthenticationMiddleware, UserSessionMiddleware};
use Domain\Shared\Exception\AccessDeniedException;
use Domain\User\Service\LoginSessionAuthority;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Domain\Shared\Operations\MaintenanceMode;
use Application\Http\Security\MaintenanceAccess;
use Application\Http\Response\MaintenanceResponse;
use Qubus\Http\Session\SessionEntity;

/** Runs after successful password authentication, before the framework creates the new cookie. */
final readonly class StartLoginSessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoginCookieReader $cookies,
        private LoginSessionAuthority $sessions,
        private UserSessionMiddleware $framework,
        private MaintenanceMode $maintenance,
        private MaintenanceAccess $maintenanceAccess,
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
        if ($this->maintenanceDenies($authenticated)) {
            $denied = new MaintenanceResponse($this->maintenance)->respond(
                $request->getMethod(),
                $request->getUri()->getPath(),
                $request->getHeaderLine('Accept'),
            );
            if ($denied !== null) {
                return $denied;
            }
        }
        $this->sessions->revokeCurrent($this->cookies->read($request));
        $values = $request->getCookieParams();
        unset($values[$this->cookies->name()]);
        $response = $this->framework->process($request->withCookieParams($values), $handler);
        if ($this->maintenanceDenies($authenticated)) {
            $denied = new MaintenanceResponse($this->maintenance)->respond(
                $request->getMethod(),
                $request->getUri()->getPath(),
                $request->getHeaderLine('Accept'),
            );
            if ($denied !== null) {
                return $denied;
            }
        }
        return $response;
    }
    private function maintenanceDenies(object $authenticated): bool
    {
        return $this->maintenance->status()->paused()
        && (!$authenticated instanceof SessionEntity || !$this->maintenanceAccess->permitsLogin($authenticated));
    }
}
