<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Service\CodefyPageBuilder;
use Codefy\Framework\Http\BaseController;
use Codefy\Framework\Proxy\Codefy;
use Psr\Http\Message\ResponseInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\ServerRequest;
use Qubus\Http\Response;
use Qubus\Routing\Psr7Router;
use ReflectionException;
use Vihzhuo\Contracts\PageContract;
use Vihzhuo\Repositories\PageRepository;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\trans;
use function Codefy\Framework\Helpers\view;
use function phpb_trans;
use function phpb_url;
use function Qubus\Security\Helpers\t__;

final class WebsiteManagerController extends BaseController
{
    private string $managerIndexTemplate = 'framework::backend/manager/index';
    private string $pageSettingsTemplate = 'framework::backend/manager/page-settings';

    public function __construct(protected Psr7Router $router)
    {
    }

    /**
     * @throws \Exception
     */
    public function index(ServerRequest $request): ResponseInterface
    {
        if (false === gate(permission: 'vihzhuo:manage')) {
            Codefy::$PHP->flash->error(
                message: t__(msgid: 'Access denied.', domain: 'devflow')
            );

            return $this->redirect($this->router->url(name: 'admin.home'));
        }

        $this->vihzhuoInstance();
        $query = array_filter($request->getQueryParams(), 'is_string', ARRAY_FILTER_USE_KEY);
        $route = $this->stringValue($query, 'route');
        $action = $this->stringValue($query, 'action');

        if ($route === 'page_settings') {
            if ($action === 'create') {
                return $this->handleCreate($request);
            }

            $pageId = $query['page'] ?? null;
            $pageRepository = new PageRepository();
            $page = null;
            if (is_int($pageId) || is_string($pageId)) {
                $page = $pageRepository->findWithId($pageId);
            }

            if (! ($page instanceof PageContract)) {
                return $this->redirect(phpb_url('website_manager'));
            }

            if ($action === 'edit') {
                return $this->handleEdit($page, $request);
            } elseif ($action === 'destroy') {
                if ($request->getMethod() !== 'POST') {
                    return new Response(status: 405, headers: ['Allow' => 'POST']);
                }
                return $this->handleDestroy($page);
            }
        }

        $pageRepository = new PageRepository();
        $pages = $pageRepository->getAll();

        return view(
            template: $this->managerIndexTemplate,
            data: [
                'title' => trans('Website Manager'),
                'pages' => $pages,
            ]
        );
    }

    /**
     * @throws TypeException
     */
    private function vihzhuoInstance(): void
    {
        $config = config()->array(key: 'vihzhuo');

        new CodefyPageBuilder(array_filter($config, 'is_string', ARRAY_FILTER_USE_KEY));
    }

    /**
     * @throws \Exception
     */
    private function renderPageSettings(?PageContract $page = null): ResponseInterface
    {
        $action = isset($page) ? 'edit' : 'create';
        $theme = phpb_instance(name: 'theme', params: [
            phpb_config(key: 'theme'),
            phpb_config(key: 'theme.active_theme')
        ]);

        return view(
            template: $this->pageSettingsTemplate,
            data: [
                'title' => trans('Website Manager'),
                'page' => $page,
                'action' => $action,
                'theme' => $theme,
            ]
        );
    }

    /**
     * @throws \Exception
     */
    private function handleCreate(ServerRequest $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            $pageRepository = new PageRepository();
            $page = $pageRepository->create($this->parsedBody($request));
            if ($page) {
                /** @var string $message */
                $message = phpb_trans(key: 'website-manager.page-created');
                Codefy::$PHP->flash->success($message);

                return $this->redirect(phpb_url('website_manager'));
            }
        }

        return $this->renderPageSettings();
    }

    /**
     * @throws \Exception
     */
    private function handleEdit(PageContract $page, ServerRequest $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'POST') {
            $pageRepository = new PageRepository();
            $success = $pageRepository->update($page, $this->parsedBody($request));
            if ($success) {
                /** @var string $message */
                $message = phpb_trans(key: 'website-manager.page-updated');
                Codefy::$PHP->flash->success($message);

                return $this->redirect(phpb_url(module: 'website_manager'));
            }
        }

        return $this->renderPageSettings($page);
    }

    /** @throws ReflectionException */
    private function handleDestroy(PageContract $page): ResponseInterface
    {
        $pageRepository = new PageRepository();
        $pageRepository->destroy($page->getId());
        /** @var string $message */
        $message = phpb_trans(key: 'website-manager.page-deleted');
        Codefy::$PHP->flash->success($message);

        return $this->redirect(phpb_url('website_manager'));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function parsedBody(ServerRequest $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? array_filter($body, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }
}
