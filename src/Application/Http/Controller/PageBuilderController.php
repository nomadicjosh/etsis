<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Service\CodefyPageBuilder;
use Codefy\Framework\Http\BaseController;
use Codefy\Framework\Proxy\Codefy;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Exceptions\NamedRouteNotFoundException;
use Qubus\Routing\Exceptions\RouteParamFailedConstraintException;
use Qubus\Routing\Psr7Router;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\view;
use function Qubus\Security\Helpers\t__;

final class PageBuilderController extends BaseController
{
    public function __construct(protected Psr7Router $router)
    {
    }

    /**
     * @throws \Exception
     */
    public function assets(ServerRequest $request): ResponseInterface
    {
        $response = $this->builder()->handlePublicRequest($request);

        return $response ?? view(template: 'framework::error/404')->withStatus(404);
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function uploads(ServerRequest $request): ResponseInterface
    {
        $response = $this->builder()->handlePublicRequest($request);

        return $response ?? view(template: 'framework::error/404')->withStatus(404);
    }

    /**
     * @param ServerRequest $request
     * @return ResponseInterface
     * @throws NamedRouteNotFoundException
     * @throws RouteParamFailedConstraintException
     * @throws TypeException
     * @throws Exception
     */
    public function websiteManager(ServerRequest $request): ResponseInterface
    {
        if (false === gate(permission: 'vihzhuo:manage')) {
            Codefy::$PHP->flash->error(
                message: t__(msgid: 'Access denied.', domain: 'devflow')
            );
            return $this->redirect($this->router->url('admin.home'));
        }

        $builder = $this->builder();

        return $builder->handleRequest($request);
    }

    /**
     * @throws \Exception
     */
    public function any(ServerRequest $request): ResponseInterface
    {
        $builder = $this->builder();
        $response = $builder->handlePublicRequest($request);

        if ($response !== null) {
            return $response->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }

        if ($request->getUri()->getPath() === '/') {
            return Codefy::$PHP->make(CampusPortalController::class)->home();
        }

        return view(template: 'framework::error/404')->withStatus(404);
    }

    /**
     * @throws TypeException
     */
    private function builder(): CodefyPageBuilder
    {
        $config = config()->array('vihzhuo');

        return new CodefyPageBuilder(array_filter($config, 'is_string', ARRAY_FILTER_USE_KEY));
    }
}
