<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Application\Http\Response\MaintenanceResponse;
use Application\Http\Security\MaintenanceAccess;
use Application\Http\Security\MaintenanceRoutes;
use Domain\Shared\Operations\MaintenanceMode;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Config\ConfigContainer;
use Throwable;

final readonly class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MaintenanceMode $maintenance,
        private ConfigContainer $config,
        private ContainerInterface $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->maintenance->status()->paused()) {
            return $handler->handle($request);
        }
        $path = $request->getUri()->getPath();
        if (
            MaintenanceRoutes::authentication(
                $request->getMethod(),
                $path,
                $this->config->string('auth.login_route', 'login'),
            )
        ) {
            return $handler->handle($request);
        }
        $candidate = MaintenanceRoutes::browserCandidate(
            $path,
            $request->getCookieParams()[$this->config->string('auth.cookie_name', 'USERSESSID')] ?? null,
        );
        try {
            $allowed = $candidate && $this->container->get(MaintenanceAccess::class)->permitsRequest($request);
        } catch (Throwable) {
            $allowed = false;
        }
        if ($allowed) {
            return $handler->handle($request);
        }
        $response = new MaintenanceResponse($this->maintenance)->respond(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getHeaderLine('Accept'),
        );
        return $response ?? $handler->handle($request);
    }
}
