<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CampusFixture.php';

use Application\Http\Controller\CampusPortalController;
use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
use Qubus\Http\ServerRequest;

final class PortalAuthenticationTest extends TestCase
{
    public function testPortalUsesTheConfiguredSignInRouteForLinksAndGuestRedirects(): void
    {
        $config = Collection::factory([]);
        $config->setConfigKey('portal', ['name' => 'Fictional campus', 'theme' => 'campus-harbor', 'tagline' => '']);
        $config->setConfigKey('auth', ['login_route' => 'campus-sign-in', 'password_min_length' => 26,
            'username_min_length' => 6]);
        $gate = $this->createStub(Gate::class);
        $gate->method('isLoggedIn')->willReturn(false);
        $renderer = new CampusRenderer($config, $gate);
        $response = $renderer->render('home', 'Fictional welcome', ['programs' => [], 'news' => []]);
        self::assertStringContainsString('href="/campus-sign-in/"', (string) $response->getBody());
        $controller = new CampusPortalController(new CampusFixture()->service, $renderer, $gate);
        self::assertSame('/campus-sign-in/', $controller->student()->getHeaderLine('Location'));
        self::assertSame('/campus-sign-in/', $controller->landing(new ServerRequest())
            ->getHeaderLine('Location'));
    }
}
