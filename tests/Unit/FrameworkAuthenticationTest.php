<?php

declare(strict_types=1);

use Codefy\Framework\Auth\Repository\AuthUserRepository;
use Codefy\Framework\Auth\UserSession;
use Codefy\Framework\Http\Middleware\Auth\AuthenticationMiddleware;
use Codefy\Framework\Auth\Auth;
use Codefy\Framework\Auth\Rbac\Rbac;
use Codefy\Framework\Auth\Rbac\Resource\StorageResource;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;

final class FrameworkAuthenticationTest extends TestCase
{
    private function authenticator(AuthUserRepository $repository, ConfigContainer $config): Auth
    {
        return new Auth($repository, $config, new Rbac($this->createStub(StorageResource::class)), new ResponseFactory());
    }
    private function config(string $redirect = '/login/'): ConfigContainer
    {
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturnMap([
            ['auth.pdo.fields.identity', 'username', 'email'],
            ['auth.pdo.fields.password', 'password', 'password'],
            ['auth.http_redirect', null, $redirect],
        ]);

        return $config;
    }

    public function testLoginPassesVerifiedSessionToFrameworkSessionMiddleware(): void
    {
        $session = new UserSession()->withToken('fictional-token');
        $repository = $this->createMock(AuthUserRepository::class);
        $repository->expects(self::once())->method('authenticate')
            ->with('reader@example.invalid', ' fictional password with spaces ')->willReturn($session);
        $middleware = new AuthenticationMiddleware($this->authenticator($repository, $this->config()));
        $request = new ServerRequest([], [], '/auth/', 'POST')->withParsedBody([
            'email' => 'reader@example.invalid', 'password' => ' fictional password with spaces ',
        ]);
        $response = $middleware->process($request, new CallableRequestHandler(static function ($request) use ($session) {
            self::assertSame($session, $request->getAttribute(AuthenticationMiddleware::AUTH_ATTRIBUTE));

            return new JsonResponse(['ok' => true]);
        }));
        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvalidCredentialsCannotReachProtectedHandler(): void
    {
        $repository = $this->createMock(AuthUserRepository::class);
        $repository->expects(self::once())->method('authenticate')->willReturn(null);
        $middleware = new AuthenticationMiddleware($this->authenticator($repository, $this->config()));
        $request = new ServerRequest([], [], '/auth/', 'POST')->withParsedBody([
            'email' => 'reader@example.invalid', 'password' => 'fictional incorrect password',
        ]);
        $response = $middleware->process($request, new CallableRequestHandler(static function (): never {
            self::fail('Invalid credentials reached the protected handler.');
        }));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login/', $response->getHeaderLine('Location'));
    }

    public function testMalformedCredentialsAreRejectedWithoutRepositoryLookup(): void
    {
        $repository = $this->createMock(AuthUserRepository::class);
        $repository->expects(self::never())->method('authenticate');
        $authenticator = $this->authenticator($repository, $this->config(''));
        foreach ([null, [], ['email' => ['invalid'], 'password' => 'example'],
            ['email' => 'reader@example.invalid', 'password' => '']] as $body) {
            self::assertNull($authenticator->authenticate(
                new ServerRequest([], [], '/auth/', 'POST')->withParsedBody($body)
            ));
        }
        self::assertSame(403, $authenticator->unauthorized(new ServerRequest())->getStatusCode());
    }
}
