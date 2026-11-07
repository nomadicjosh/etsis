<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Psr7Router;

final readonly class AuthController
{
    public function __construct(private Psr7Router $router, private CampusRenderer $renderer, private Gate $gate)
    {
    }

    public function auth(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $continue = is_array($body) && ($body['continue'] ?? null) === 'apply';
        // The session cookie is written after this response. Resolve access on the next GET.
        return new RedirectResponse('/portal/sign-in/' . ($continue ? '?continue=apply' : ''));
    }

    public function login(ServerRequest $request): ResponseInterface
    {
        $continue = ($request->getQueryParams()['continue'] ?? null) === 'apply';
        if ($this->gate->isLoggedIn()) {
            return new RedirectResponse('/portal/sign-in/' . ($continue ? '?continue=apply' : ''));
        }
        return $this->renderer->render('auth', 'Welcome back', ['mode' => 'login',
            'url' => $this->router->url('auth'), 'continue' => $continue]);
    }

    public function confirmLogout(): ResponseInterface
    {
        return $this->renderer->render('auth', 'Sign out', ['mode' => 'logout',
            'url' => $this->router->url('auth.logout'), 'continue' => false]);
    }

    public function logout(): ResponseInterface
    {
        return new RedirectResponse($this->router->url('auth.login'));
    }
}
