<?php

declare(strict_types=1);

use Application\Http\Middleware\SisPrivacyMiddleware;
use Application\Http\Middleware\PrivateRouteDebugBarMiddleware;
use Psr\Container\ContainerInterface;
use Codefy\Framework\Http\Middleware\Csrf\CsrfProtectionMiddleware;
use Laminas\Diactoros\Response\JsonResponse;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;
use Qubus\Http\Cookies\Factory\HttpCookieFactory;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;

final class SisPrivacyTest extends TestCase
{
    public function testErrorsNeverExposePrivatePayloads(): void
    {
        $handler = new CallableRequestHandler(static function (): never {
            throw new RuntimeException('fictional-private-data@example.invalid');
        });
        $response = new SisPrivacyMiddleware()->process(new ServerRequest([], [], '/sis/students/invalid/', 'GET'), $handler);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('fictional-private-data', (string) $response->getBody());
    }

    public function testPrivateRouteNeverConstructsDebugCollectors(): void
    {
        $application = $this->createMock(ContainerInterface::class);
        $application->expects(self::never())->method('get');
        $middleware = new PrivateRouteDebugBarMiddleware($application);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        $response = $middleware->process(new ServerRequest([], [], '/%73is/students/example/', 'GET'), $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCmsAndConfiguredLoginRoutesNeverConstructDebugCollectors(): void
    {
        $application = $this->createMock(ContainerInterface::class);
        $application->expects(self::never())->method('get');
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        foreach (['portal.cms', 'auth.login', 'register.create'] as $name) {
            $request = new ServerRequest([], [], '/custom-campus-page/', 'GET')
                ->withAttribute(\Qubus\Routing\Route\RouteAttributes::NAME, $name);
            self::assertSame(200, new PrivateRouteDebugBarMiddleware($application)->process($request, $handler)
                ->getStatusCode());
            self::assertStringContainsString('no-store', new SisPrivacyMiddleware()->process($request, $handler)
                ->getHeaderLine('Cache-Control'));
        }
    }

    public function testMissingCsrfTokenCannotReachMutation(): void
    {
        $config = $this->createStub(ConfigContainer::class);
        $cookie = $this->createStub(HttpCookieFactory::class);
        $csrf = new CsrfProtectionMiddleware($config, $cookie);
        $mutation = new CallableRequestHandler(static function (): never {
            self::fail('Mutation ran without a CSRF token.');
        });
        $handler = new CallableRequestHandler(static fn ($request) => $csrf->process($request, $mutation));
        $response = new SisPrivacyMiddleware()->process(new ServerRequest([], [], '/sis/persons/', 'POST'), $handler);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testWorkspaceJsonAcceptsEmptyObjectsButRejectsLists(): void
    {
        $middleware = new \Application\Http\Middleware\WorkspaceJsonBodyMiddleware();
        $request = new ServerRequest([], [], '/sis/forms/example/publish/', 'POST');
        $request = $request->withHeader('Content-Type', 'application/json')
            ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
        $request->getBody()->write('{}');
        $request->getBody()->rewind();
        $handler = new CallableRequestHandler(static function ($request): JsonResponse {
            self::assertSame([], $request->getParsedBody());

            return new JsonResponse(['ok' => true]);
        });
        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());
        $request->getBody()->rewind();
        $request->getBody()->write('[]');
        $request->getBody()->rewind();
        $this->expectException(InvalidArgumentException::class);
        $middleware->process($request, $handler);
    }

    public function testWorkspaceFormEnvelopePreservesTypedValuesForAllMutationMethods(): void
    {
        $data = ['credits_milli' => 3000, 'active' => false, 'required' => true,
            'zero' => 0, 'optional' => null, 'rules' => [['value' => 3]], 'code' => 'Math-101'];
        $encoded = http_build_query(['_workspace_json' => json_encode($data, JSON_THROW_ON_ERROR)]);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request = new ServerRequest([], [], '/sis/courses/', $method)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8')
                ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
            $request->getBody()->write($encoded);
            $request->getBody()->rewind();
            $handler = new CallableRequestHandler(static function ($request) use ($data, $method): JsonResponse {
                self::assertSame($data, $request->getParsedBody());
                self::assertSame($method, $request->getMethod());

                return new JsonResponse(['ok' => true]);
            });
            self::assertSame(200, new \Application\Http\Middleware\WorkspaceJsonBodyMiddleware()
                ->process($request, $handler)->getStatusCode());
        }
    }

    public function testWorkspaceEnvelopeRejectsMalformedMixedAndOversizedPayloads(): void
    {
        $handler = new CallableRequestHandler(static function (): never {
            self::fail('Invalid workspace envelope reached a controller.');
        });
        foreach ([['_workspace_json' => 'invalid'], ['_workspace_json' => '[]'],
            ['_workspace_json' => ['invalid']], ['_workspace_json' => '{}', '_method' => 'DELETE'],
            ['_workspace_json' => str_repeat('x', 2097153)]] as $data) {
            $request = new ServerRequest([], [], '/sis/courses/', 'POST')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
            $request->getBody()->write(http_build_query($data));
            $request->getBody()->rewind();
            $response = new SisPrivacyMiddleware()->process($request, new CallableRequestHandler(
                static fn ($request) => new \Application\Http\Middleware\WorkspaceJsonBodyMiddleware()
                    ->process($request, $handler)
            ));
            self::assertSame(422, $response->getStatusCode());
        }
    }

    public function testOrdinaryPrivateFormKeepsItsParsedBodyAndStream(): void
    {
        $request = new ServerRequest([], [], '/admin/profile/update/', 'POST')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['first_name' => 'Example'])
            ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
        $request->getBody()->write('first_name=Example');
        $request->getBody()->rewind();
        new \Application\Http\Middleware\WorkspaceJsonBodyMiddleware()->process($request,
            new CallableRequestHandler(static function ($request): JsonResponse {
                self::assertSame(['first_name' => 'Example'], $request->getParsedBody());
                self::assertSame('first_name=Example', $request->getBody()->getContents());

                return new JsonResponse(['ok' => true]);
            }));
    }
}
