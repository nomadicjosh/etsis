<?php

declare(strict_types=1);

namespace Tests\Unit;

use Application\Http\Middleware\PublicRegistrationThrottleMiddleware;
use Application\Http\Security\RegistrationCounterLock;
use Codefy\Framework\Http\Throttle\Condition;
use Codefy\Framework\Http\Throttle\RateLimiter;
use Infrastructure\Security\FileRegistrationCounterLock;
use Laminas\Diactoros\Response\JsonResponse;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qubus\Cache\InMemoryCache;
use Qubus\Config\Collection;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;

final class PublicRegistrationThrottleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/etsis-registration-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function middleware(CacheItemPoolInterface $cache, int $limit = 2): PublicRegistrationThrottleMiddleware
    {
        $config = Collection::factory([]);
        $config->setConfigKey('throttle', ['registration' => ['max_attempts' => $limit, 'ttl' => 600]]);
        $config->setConfigKey('app', ['crypto_key' => str_repeat('fictional-key-', 4)]);
        $config->setConfigKey('auth', ['login_route' => 'staff-access']);

        return new PublicRegistrationThrottleMiddleware(
            $config,
            $cache,
            new FileRegistrationCounterLock($this->directory),
        );
    }

    private function request(string $address = '192.0.2.10'): ServerRequest
    {
        return new ServerRequest(['REMOTE_ADDR' => $address], [], '/create/', 'POST')
            ->withHeader('Accept', 'application/json');
    }

    public function testLimitsPersistAcrossMiddlewareInstancesAndBlockedAttemptsNeverReachAccountCreation(): void
    {
        $cache = new InMemoryCache();
        $calls = 0;
        $handler = new CallableRequestHandler(static function () use (&$calls): ResponseInterface {
            $calls++;

            return new JsonResponse(['accepted' => true]);
        });
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = $this->middleware($cache)->process($this->request(), $handler);
            self::assertSame($attempt <= 2 ? 200 : 429, $response->getStatusCode());
        }
        self::assertSame(2, $calls);
        self::assertGreaterThanOrEqual(1, (int) $response->getHeaderLine('Retry-After'));
        self::assertLessThanOrEqual(600, (int) $response->getHeaderLine('Retry-After'));
    }

    public function testRegistrationAndFrameworkSignInCountersStayIndependentInTheSameCache(): void
    {
        $cache = new InMemoryCache();
        $signIn = new RateLimiter($cache);
        $signIn->add(new Condition(600, 2));
        $signIn->increment('192.0.2.10', 2);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        $registration = $this->middleware($cache);

        self::assertSame(200, $registration->process($this->request(), $handler)->getStatusCode());
        self::assertSame(200, $registration->process($this->request(), $handler)->getStatusCode());
        self::assertSame(429, $registration->process($this->request(), $handler)->getStatusCode());
        self::assertSame(2, $signIn->getIntervals('192.0.2.10')[0]->count);
        self::assertSame(200, $registration->process($this->request('192.0.2.11'), $handler)->getStatusCode());
    }

    public function testForwardingHeadersCannotSelectANewRegistrationCounter(): void
    {
        $middleware = $this->middleware(new InMemoryCache(), 1);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        self::assertSame(200, $middleware->process($this->request(), $handler)->getStatusCode());
        $request = $this->request()->withHeader('X-Forwarded-For', '192.0.2.99')
            ->withHeader('Forwarded', 'for=192.0.2.98')->withHeader('X-Real-IP', '192.0.2.97');
        self::assertSame(429, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testEquivalentIpv6SpellingsShareOneCounter(): void
    {
        $middleware = $this->middleware(new InMemoryCache(), 1);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        self::assertSame(200, $middleware->process($this->request('2001:db8::1'), $handler)->getStatusCode());
        self::assertSame(429, $middleware->process(
            $this->request('2001:0db8:0000:0000:0000:0000:0000:0001'),
            $handler,
        )->getStatusCode());
    }

    public function testBrowserAndJsonLimitResponsesArePrivateAndDoNotEchoRegistrationInput(): void
    {
        foreach (['text/html', 'application/json'] as $accept) {
            $middleware = $this->middleware(new InMemoryCache(), 1);
            $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
            $request = $this->request()->withHeader('Accept', $accept)->withParsedBody([
                'email' => 'fictional-private@example.invalid', 'password' => 'Fictional-secret-never-echoed',
            ]);
            $middleware->process($request, $handler);
            $response = $middleware->process($request, $handler);
            $body = (string) $response->getBody();
            self::assertSame(429, $response->getStatusCode());
            self::assertStringStartsWith($accept, $response->getHeaderLine('Content-Type'));
            self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
            self::assertSame('Accept', $response->getHeaderLine('Vary'));
            self::assertStringContainsString('You can still sign in', $body);
            foreach (['192.0.2.10', 'fictional-private', 'Fictional-secret'] as $private) {
                self::assertStringNotContainsString($private, $body);
            }
            if ($accept === 'text/html') {
                self::assertStringContainsString('Please wait before trying again', $body);
            } else {
                self::assertArrayHasKey('error', json_decode($body, true, flags: JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testMissingPeerAddressesShareABoundedFallbackInsteadOfBypassingTheLimit(): void
    {
        $middleware = $this->middleware(new InMemoryCache(), 1);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        $request = new ServerRequest([], [], '/create/', 'POST');
        self::assertSame(200, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(429, $middleware->process($request->withHeader('X-Forwarded-For', '192.0.2.20'), $handler)
            ->getStatusCode());
    }

    public function testUnavailableCounterStorageCannotRunAccountCreation(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())->method('getItem')
            ->willThrowException(new \RuntimeException('Cache unavailable.'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $middleware = $this->middleware($cache);

        $response = $middleware->process($this->request(), $handler);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->getHeaderLine('Retry-After'));
        self::assertStringNotContainsString('Cache unavailable', (string) $response->getBody());
    }

    public function testIpv4MappedIpv6CannotSelectAnotherBucket(): void
    {
        $middleware = $this->middleware(new InMemoryCache(), 1);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        self::assertSame(200, $middleware->process($this->request(), $handler)->getStatusCode());
        self::assertSame(429, $middleware->process($this->request('::ffff:192.0.2.10'), $handler)->getStatusCode());
    }

    public function testRejectedStorageSaveStopsBeforeCreatingAnAccount(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn(new \Qubus\Cache\Psr6\Item('fictional'));
        $cache->expects(self::once())->method('save')->willReturn(false);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        self::assertSame(503, $this->middleware($cache)->process($this->request(), $handler)->getStatusCode());
    }

    public function testLockContentionReturnsPrivateRetryAndRecoversAfterRelease(): void
    {
        $middleware = $this->middleware(new InMemoryCache(), 1);
        $identifier = hash_hmac(
            'sha256',
            'public-registration:' . inet_pton('192.0.2.10'),
            str_repeat('fictional-key-', 4),
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $lock = new FileRegistrationCounterLock($this->directory);
        $lock->run($identifier, function () use ($middleware, $handler): void {
            $response = $middleware->process($this->request()->withHeader('Accept', 'text/html'), $handler);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('href="/staff-access/"', (string) $response->getBody());
            self::assertStringContainsString('href="/register/"', (string) $response->getBody());
            self::assertStringNotContainsString('192.0.2.10', (string) $response->getBody());
        });
        $allowed = new CallableRequestHandler(static fn () => new JsonResponse(['ok' => true]));
        self::assertSame(200, $middleware->process($this->request(), $allowed)->getStatusCode());
    }

    public function testLockIsReleasedBeforeControllerAndControllerErrorsPropagate(): void
    {
        $middleware = $this->middleware(new InMemoryCache());
        $identifier = hash_hmac(
            'sha256',
            'public-registration:' . inet_pton('192.0.2.10'),
            str_repeat('fictional-key-', 4),
        );
        $handler = new CallableRequestHandler(function () use ($identifier): ResponseInterface {
            $acquired = false;
            $lock = new FileRegistrationCounterLock($this->directory);
            $lock->run($identifier, static function () use (&$acquired): void {
                $acquired = true;
            });
            self::assertTrue($acquired);
            throw new \LogicException('Controller failure.');
        });
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Controller failure.');
        $middleware->process($this->request(), $handler);
    }

    public function testInvalidConfigurationFailsClosedWithoutTouchingCounterOrHandler(): void
    {
        foreach ([['max_attempts' => 0, 'ttl' => 600], ['max_attempts' => 1, 'ttl' => 86401]] as $settings) {
            $config = Collection::factory([]);
            $config->setConfigKey('throttle', ['registration' => $settings]);
            $config->setConfigKey('app', ['crypto_key' => str_repeat('fictional-key-', 4)]);
            $cache = $this->createMock(CacheItemPoolInterface::class);
            $cache->expects(self::never())->method('getItem');
            $lock = $this->createMock(RegistrationCounterLock::class);
            $lock->expects(self::never())->method('run');
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::never())->method('handle');
            $middleware = new PublicRegistrationThrottleMiddleware($config, $cache, $lock);
            $response = $middleware->process($this->request(), $handler);
            self::assertSame(503, $response->getStatusCode());
        }
    }

    public function testInvalidFormAttemptsStillConsumeTheSameAllowance(): void
    {
        $middleware = $this->middleware(new InMemoryCache(), 1);
        $handler = new CallableRequestHandler(static fn () => new JsonResponse(['error' => 'Check your form.'], 422));
        self::assertSame(422, $middleware->process($this->request()->withParsedBody([]), $handler)->getStatusCode());
        self::assertSame(429, $middleware->process($this->request(), $handler)->getStatusCode());
    }

    public function testMissingIdentifierKeyStopsBeforeCounterOrAccountCreation(): void
    {
        $config = Collection::factory([]);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');
        $lock = $this->createMock(RegistrationCounterLock::class);
        $lock->expects(self::never())->method('run');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $middleware = new PublicRegistrationThrottleMiddleware($config, $cache, $lock);
        $response = $middleware->process($this->request(), $handler);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('crypto_key', (string) $response->getBody());
    }
}
