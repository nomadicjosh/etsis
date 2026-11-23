<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Application\Http\Controller\CampusPortalController;
use Application\View\CampusRenderer;
use Codefy\Framework\Auth\Gate;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
use Qubus\Config\ConfigContainer;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\get_fresh_bootstrap;

final class PortalAuthenticationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPortalUsesTheConfiguredSignInRouteForLinksAndGuestRedirects(): void
    {
        $config = Collection::factory([]);
        $config->setConfigKey('app', ['language' => 'en', 'charset' => 'UTF-8', 'locale_domain' => 'etsis']);
        $config->setConfigKey('portal', ['name' => 'Fictional campus', 'theme' => 'campus-harbor', 'tagline' => '']);
        $config->setConfigKey('auth', ['login_route' => 'campus-sign-in', 'password_min_length' => 26,
            'username_min_length' => 6]);
        $app = get_fresh_bootstrap();
        $app->delegate(ConfigContainer::class, static fn () => $config);
        $app->delegate('codefy.config', static fn () => $config);
        $gate = $this->createStub(Gate::class);
        $gate->method('isLoggedIn')->willReturn(false);
        $renderer = new CampusRenderer($config, $gate);
        $response = $renderer->render('home', 'Fictional welcome', ['programs' => [], 'news' => []]);
        self::assertStringContainsString('href="/campus-sign-in/"', (string) $response->getBody());
        $controller = new CampusPortalController(new CampusFixture()->service, $renderer, $gate);
        self::assertSame('/campus-sign-in/', $controller->student()->getHeaderLine('Location'));
        self::assertSame('/campus-sign-in/', $controller->admissionsUpdates()->getHeaderLine('Location'));
        self::assertSame('/campus-sign-in/', $controller->landing(new ServerRequest())
            ->getHeaderLine('Location'));
    }
}
