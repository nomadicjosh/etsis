<?php

declare(strict_types=1);

namespace Application\Http\Middleware;

use Codefy\Framework\Auth\Sentinel;
use Codefy\Framework\Http\Middleware\Auth\AuthenticationMiddleware;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Qubus\Http\Session\SessionEntity;
use Qubus\Config\ConfigContainer;

/** Uses framework credential verification with a fixed, non-identifying failure notice. */
final readonly class PasswordAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private Sentinel $auth, private ConfigContainer $config)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $user = $this->auth->authenticate($request);
        if (!($user instanceof SessionEntity)) {
            $body = $request->getParsedBody();
            $continue = is_array($body) && ($body["continue"] ?? null) === "apply";
            return new RedirectResponse(
                "/" .
                    trim($this->config->string("auth.login_route", "login"), "/") .
                    "/" .
                    "?notice=credentials" .
                    ($continue ? "&continue=apply" : ""),
            );
        }
        return $handler->handle($request->withAttribute(AuthenticationMiddleware::AUTH_ATTRIBUTE, $user));
    }
}
