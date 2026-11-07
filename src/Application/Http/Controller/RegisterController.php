<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Codefy\Framework\Http\BaseController;
use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use Domain\User\Validator\RegisterUserValidator;
use Domain\User\Service\UserService;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Exceptions\NamedRouteNotFoundException;
use Qubus\Routing\Exceptions\RouteParamFailedConstraintException;
use Qubus\Routing\Psr7Router;

final class RegisterController extends BaseController
{
    public function __construct(protected Psr7Router $router, private CampusRenderer $renderer, private Gate $gate)
    {
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     * @throws \Exception
     */
    public function show(): ResponseInterface
    {
        if ($this->gate->isLoggedIn()) {
            return $this->redirect(
                url: $this->router->url(
                    name: 'portal.student'
                )
            );
        }

        return $this->renderer->render(
            'auth',
            'Begin your next chapter',
            [
                'mode' => 'register', 'continue' => true,
                'url' => $this->router->url(name: 'register.create'),
            ]
        );
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function create(ServerRequest $request, UserService $service): ResponseInterface
    {
        if (false === $service->createAccount(RegisterUserValidator::make($request))) {
            return $this->redirect(
                url: $this->router->url(
                    name: 'register.show'
                )
            );
        }

        return $this->redirect(
            url: $this->router->url(
                name: 'auth.login'
            ) . '?continue=apply'
        );
    }
}
