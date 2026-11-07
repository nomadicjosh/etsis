<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Codefy\Framework\Http\BaseController;
use Application\View\CampusRenderer;
use Qubus\Config\ConfigContainer;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\User\Validator\UpdateUserPasswordValidator;
use Domain\User\Validator\UpdateProfileValidator;
use Domain\User\Service\UserService;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Exceptions\NamedRouteNotFoundException;
use Qubus\Routing\Exceptions\RouteParamFailedConstraintException;
use Qubus\Routing\Psr7Router;

use function Codefy\Framework\Helpers\trans;
use function Codefy\Framework\Helpers\user;
use function Codefy\Framework\Helpers\view;

final class ProfileController extends BaseController
{
    private string $profileTemplate = 'framework::backend/profile';

    public function __construct(protected Psr7Router $router)
    {
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function index(CampusRenderer $renderer, ConfigContainer $config): ResponseInterface
    {
        $current = user();
        if (!is_object($current)) {
            throw new AccessDeniedException('Sign in to manage your profile.');
        }
        $profile = [];
        foreach (['username', 'first_name', 'middle_name', 'last_name', 'email'] as $field) {
            $profile[$field] = is_string($current->{$field} ?? null) ? $current->{$field} : '';
        }

        return view(
            template: $this->profileTemplate,
            data: [
                'title' => trans('User Profile'),
                'profile' => $profile,
                'passwordLength' => $config->integer('auth.password_min_length', 26),
                'backUrl' => $renderer->canUseWorkspace() ? '/admin/' : '/portal/student/',
                'url' => $this->router->url(name: 'admin.profile.update'),
            ]
        )->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; "
                . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; "
                . "connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    }

    /**
     * @throws RouteParamFailedConstraintException
     * @throws NamedRouteNotFoundException
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function update(
        ServerRequest $request,
        UserService $service
    ): ResponseInterface {
        if (!empty($request->get('password'))) {
            $service->updatePassword(
                UpdateUserPasswordValidator::make($request)
            );

            return $this->redirect(
                url: $this->router->url(
                    name: 'auth.login'
                )
            );
        } else {
            $service->updateUser(
                UpdateProfileValidator::make($request)
            );
        }

        return $this->redirect(
            url: $this->router->url(
                name: 'admin.profile'
            )
        );
    }
}
