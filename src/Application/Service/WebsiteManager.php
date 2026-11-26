<?php

declare(strict_types=1);

namespace Application\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\Http\Response;
use Vihzhuo\Contracts\PageContract;

use function Codefy\Framework\Helpers\view;

final class WebsiteManager extends \Vihzhuo\Modules\WebsiteManager\WebsiteManager
{
    public function handleRequest(
        ServerRequestInterface $request,
        ?string $route = null,
        ?string $action = null
    ): ResponseInterface {
        if ($route === 'settings' && $action !== 'update') {
            \Codefy\Framework\Proxy\Codefy::$PHP->make(
                \Domain\Portal\Service\PagePublicationService::class,
            )->directory();
            return view('framework::backend/manager/site', [
                'mode' => 'settings', 'languageOptions' => phpb_trans('languages'),
                'activeLanguages' => array_keys(phpb_active_languages()),
                'settingsAction' => phpb_url('website_manager', ['route' => 'settings', 'action' => 'update']),
            ]);
        }
        if ($route === 'form-inbox') {
            \Codefy\Framework\Proxy\Codefy::$PHP->make(
                \Domain\WebsiteForms\Service\WebsiteFormInboxService::class
            )->page();
            return view('framework::backend/manager/site', ['mode' => 'form-inbox']);
        }
        if ($route === 'menus') {
            \Codefy\Framework\Proxy\Codefy::$PHP->make(\Domain\Portal\Service\NavigationService::class)->get('primary');
            return view('framework::backend/manager/site', ['mode' => 'menus']);
        }
        if ($action === 'destroy' && $request->getMethod() !== 'POST') {
            return new Response(status: 405, headers: ['Allow' => 'POST']);
        }

        return parent::handleRequest($request, $route, $action);
    }

    public function renderOverview(): ResponseInterface
    {
        \Codefy\Framework\Proxy\Codefy::$PHP->make(\Domain\Portal\Service\PagePublicationService::class)->directory();
        return view('framework::backend/manager/site', ['mode' => 'pages']);
    }

    public function handleUpdateSettings(ServerRequestInterface $request): ResponseInterface
    {
        return parent::handleUpdateSettings($request)->withHeader(
            'Location',
            phpb_url('website_manager', ['route' => 'settings']),
        );
    }

    public function renderPageSettings(?PageContract $page = null): ResponseInterface
    {
        return view('framework::backend/manager/page-settings', [
            'title' => 'Website Manager',
            'page' => $page,
            'action' => $page === null ? 'create' : 'edit',
            'theme' => phpb_instance('theme', [phpb_config('theme'), phpb_config('theme.active_theme')]),
        ]);
    }
}
