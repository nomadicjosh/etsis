<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\View\CampusRenderer;
use Application\Http\Security\LoginCookieReader;
use Domain\User\Service\LoginSessionAuthority;
use Codefy\Framework\Auth\Gate;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Exceptions\NamedRouteNotFoundException;
use Qubus\Routing\Exceptions\RouteParamFailedConstraintException;
use Qubus\Routing\Psr7Router;
use Domain\Shared\Operations\MaintenanceMode;
use Application\Http\Security\ApplicationContinuation;

final readonly class AuthController
{
    public function __construct(
        private Psr7Router $router,
        private CampusRenderer $renderer,
        private Gate $gate,
        private LoginCookieReader $cookies,
        private LoginSessionAuthority $sessions,
        private MaintenanceMode $maintenance,
    ) {
    }

    public function auth(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $query = ApplicationContinuation::query(is_array($body) ? $body : []);
        // The session cookie is written after this response. Resolve access on the next GET.
        return new RedirectResponse($this->maintenance->status()->paused()
            ? '/admin/' : "/portal/sign-in/" . $query);
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     */
    public function login(ServerRequest $request): ResponseInterface
    {
        $continue = ($request->getQueryParams()["continue"] ?? null) === "apply";
        $query = ApplicationContinuation::query($request->getQueryParams());
        $maintenance = $this->maintenance->status()->paused();
        if (!$maintenance && $this->gate->isLoggedIn()) {
            return new RedirectResponse("/portal/sign-in/" . $query);
        }
        $failed = ($request->getQueryParams()["notice"] ?? null) === "credentials";
        return $this->renderer->render("auth", $failed ? "Check your sign-in details" : "Welcome back", [
            "signInFailed" => $failed,
            "mode" => "login",
            "url" => $this->router->url("auth"),
            "continue" => $continue,
            'applicationSelection' => $continue ? ApplicationContinuation::selection($request->getQueryParams()) : [],
            "maintenanceActive" => $maintenance,
        ]);
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     */
    public function confirmLogout(): ResponseInterface
    {
        return $this->renderer->render("auth", "Sign out", [
            "mode" => "logout",
            "url" => $this->router->url("auth.logout"),
            "continue" => false,
        ]);
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     */
    public function logout(ServerRequest $request): ResponseInterface
    {
        $this->sessions->revokeCurrent($this->cookies->read($request));
        return new RedirectResponse($this->router->url("auth.login"));
    }
}
