<?php

declare(strict_types=1);

namespace Application\Service;

use Codefy\Framework\Proxy\Codefy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\Exception\Data\TypeException;
use Qubus\Http\Factories\EmptyResponseFactory;
use Vihzhuo\Contracts\AuthContract;

use function Codefy\Framework\Helpers\config;
use function Codefy\Framework\Helpers\gate;
use function Codefy\Framework\Helpers\site_url;
use function phpb_redirect;

class VihzhuoAuth implements AuthContract
{
    /**
     * @param ServerRequestInterface $request
     * @param string|null $action
     * @inheritDoc
     * @throws TypeException
     */
    public function handleRequest(ServerRequestInterface $request, ?string $action = null): ?ResponseInterface
    {
        if (phpb_in_module('auth')) {
            if ($this->isAuthenticated()) {
                return phpb_redirect(url: phpb_url(module: 'website_manager'));
            }

            Codefy::$PHP->flash->error(message: 'Access denied');

            return phpb_redirect(url: site_url(path: config()->string('auth.login_route')));
        } elseif ($action === 'logout') {
            return phpb_redirect(url: site_url(path: 'logout'));
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function isAuthenticated(): bool
    {
        return gate()->isLoggedIn() && gate(permission: 'vihzhuo:manage');
    }

    /**
     * @inheritDoc
     * @throws TypeException
     */
    public function requireAuth(): ?ResponseInterface
    {
        if (!$this->isAuthenticated()) {
            return phpb_redirect(
                url: site_url(
                    path: config()->string(key: 'auth.login_route')
                )
            );
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function renderLoginForm(): ResponseInterface
    {
        return EmptyResponseFactory::create();
    }
}
