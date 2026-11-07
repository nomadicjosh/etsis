<?php

declare(strict_types=1);

namespace Application\Service;

use Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware;
use Codefy\Framework\Http\RequestContext;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\Response;
use Vihzhuo\Contracts\PageContract;
use Vihzhuo\Modules\GrapesJS\PageBuilder;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\view;

final class PageEditor extends PageBuilder
{
    public function renderPageBuilder(PageContract $page): ResponseInterface
    {
        if ($page->getLayout() === 'portal' && $page->getBuilderData() === []) {
            // Preview the starter on an empty portal page. Saving still requires the normal POST and CSRF token.
            $page->setData(['data' => CampusPageTemplate::data()], false);
        }
        return parent::renderPageBuilder($page);
    }

    public function handleRequest(
        ServerRequestInterface $request,
        ?string $route = null,
        ?string $action = null,
        ?PageContract $page = null
    ): ?ResponseInterface {
        if (in_array($action, ['store', 'upload', 'upload_delete'], true) && $request->getMethod() !== 'POST') {
            return new Response(status: 405, headers: ['Allow' => 'POST']);
        }

        return parent::handleRequest($request, $route, $action, $page);
    }

    /**
     * @throws TypeException
     * @throws Exception
     */
    public function customScripts(string $location, ?string $scripts = null): string
    {
        $custom = parent::customScripts($location, $scripts);
        if ($location !== 'head' || $scripts !== null) {
            return $custom;
        }

        return $custom . (string) view('framework::backend/manager/editor-csrf', [
            'header' => config()->string('csrf.header'),
            'token' => RequestContext::get()->getAttribute(CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE),
        ])->getBody();
    }
}
