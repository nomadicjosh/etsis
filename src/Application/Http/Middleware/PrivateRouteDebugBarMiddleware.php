<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Codefy\Framework\Http\Middleware\DebugBarMiddleware;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PrivateRouteDebugBarMiddleware implements MiddlewareInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (SisPrivacyMiddleware::protects($request)) {
            return $handler->handle($request);
        }

        // Resolve collectors only after excluding private SIS requests.
        return $this->container->get(DebugBarMiddleware::class)->process($request, $handler);
    }
}
