<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/SisFixture.php";
use Application\Http\Middleware\{LoginSessionAuthorityMiddleware, SisPrivacyMiddleware};
use Codefy\Framework\Http\Middleware\Auth\{
    UserSessionMiddleware,
    UserCookieDecryptMiddleware,
    AuthenticationMiddleware,
    AuthTokenAware,
};
use Defuse\Crypto\{Crypto, Key};
use Domain\User\Service\LoginSessionAuthority;
use Infrastructure\Security\{
    FrameworkLoginCookieReader,
    SqlLoginSessionRepository,
    SqlLoginAccountStateReader,
};
use Laminas\Diactoros\Response\JsonResponse;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
use Qubus\Http\ServerRequest;
use Qubus\Http\Cookies\Factory\HttpCookieFactory;
use Qubus\Http\Cookies\SetCookieCollection;
use Qubus\Routing\Handlers\CallableRequestHandler;
final class LoginSessionMiddlewareTest extends TestCase
{
    private SisFixture $f;
    private Collection $config;
    private FrameworkLoginCookieReader $reader;
    private LoginSessionAuthority $authority;
    private LoginSessionAuthorityMiddleware $guard;
    private Key $key;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->f->clock->time = new DateTimeImmutable("now", new DateTimeZone("UTC"));
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN token VARCHAR(191)");
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(191)");
        $this->f->sql->update(
            "users",
            ["token" => "fictional-token", "password" => "fictional-password-hash"],
            ["user_id" => $this->f->access->actor->value],
        );
        $this->key = Key::createNewRandomKey();
        $this->config = Collection::factory([]);
        $this->config->setConfigKey("app", ["crypto_key" => $this->key->saveToAsciiSafeString()]);
        $this->config->setConfigKey("auth", ["cookie_name" => "USERSESSID"]);
        $this->config->setConfigKey("cookies", ["lifetime" => 3600, "remember" => 2592000]);
        $this->reader = new FrameworkLoginCookieReader($this->config, $this->f->clock);
        $this->authority = new LoginSessionAuthority(
            new SqlLoginSessionRepository($this->f->sql),
            new SqlLoginAccountStateReader($this->f->sql),
            $this->f->access,
            $this->f->transactions,
            $this->f->clock,
        );
        $this->guard = new LoginSessionAuthorityMiddleware($this->reader, $this->authority);
    }
    private function cookie(): string
    {
        $codec = new class ($this->config) {
            use AuthTokenAware;
            public function __construct(protected Collection $configContainer) {}
            public function issue(): string
            {
                return $this->encryptAuthToken("fictional-token", 3600);
            }
        };
        return $codec->issue();
    }
    private function request(string $cookie, string $path = "/portal/student/"): ServerRequest
    {
        return new ServerRequest([], [], $path, "GET")
            ->withCookieParams(["USERSESSID" => $cookie, "unrelated" => "preserved"])
            ->withAttribute(UserCookieDecryptMiddleware::USER_COOKIE, "fictional-token");
    }
    public function testReaderUsesActualFrameworkEnvelopeWithoutExposingToken(): void
    {
        $cookie = $this->cookie();
        $proof = $this->reader->read($this->request($cookie));
        self::assertNotNull($proof);
        self::assertSame(hash("sha256", $cookie), $proof->cookieHash);
        self::assertSame(hash("sha256", "fictional-token"), $proof->tokenHash);
        self::assertGreaterThanOrEqual(time() + 3598, $proof->expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $proof->expiresAt);
        self::assertStringNotContainsString($proof->cookieHash, json_encode($proof));
        self::assertStringNotContainsString("fictional-token", json_encode($proof));
        $this->f->clock->time = new DateTimeImmutable("@" . $proof->expiresAt);
        self::assertNull($this->reader->read($this->request($cookie)));
    }
    public function testMalformedMissingExpiredWrongKeyAndWrongShapeCookiesAreAnonymous(): void
    {
        $now = $this->f->clock->now()->getTimestamp();
        $values = ["", "invalid", str_repeat("x", 16385), ["not-a-cookie"]];
        foreach (
            [
                ["version" => 1, "token" => "fictional-token", "expires" => $now],
                ["version" => 0, "token" => "fictional-token", "expires" => $now + 10],
                ["version" => 1, "token" => "", "expires" => $now + 10],
                ["version" => 1, "token" => "fictional-token", "expires" => (string) ($now + 10)],
            ]
            as $data
        ) {
            $values[] = Crypto::encrypt(json_encode($data), $this->key);
        }
        $values[] = Crypto::encrypt("not-json", $this->key);
        $values[] = Crypto::encrypt("{}", Key::createNewRandomKey());
        foreach ($values as $value) {
            self::assertNull(
                $this->reader->read(new ServerRequest()->withCookieParams(["USERSESSID" => $value])),
            );
        }
        self::assertNull($this->reader->read(new ServerRequest()));
    }
    public function testTrackedActiveAndUntrackedCookiesRetainAuthentication(): void
    {
        foreach ([false, true] as $tracked) {
            $cookie = $this->cookie();
            $request = $this->request($cookie);
            if ($tracked) {
                $this->authority->current($this->reader->read($request));
            }
            $response = $this->guard->process(
                $request,
                new CallableRequestHandler(static function ($request) use ($cookie): JsonResponse {
                    self::assertSame(
                        "fictional-token",
                        $request->getAttribute(UserCookieDecryptMiddleware::USER_COOKIE),
                    );
                    self::assertSame($cookie, $request->getCookieParams()["USERSESSID"]);
                    return new JsonResponse(["ok" => true]);
                }),
            );
            self::assertSame(200, $response->getStatusCode());
        }
    }
    public function testRevokedCookieCannotAuthenticateAndPasswordLoginGetsFreshEnvelope(): void
    {
        $cookie = $this->cookie();
        $request = $this->request($cookie, "/auth/")->withMethod("POST");
        $proof = $this->reader->read($request);
        $this->authority->revokeCurrent($proof);
        $issued = null;
        $factory = $this->createMock(HttpCookieFactory::class);
        $factory
            ->expects(self::once())
            ->method("make")
            ->willReturnCallback(static function (
                string $name,
                ?string $value = null,
                ?int $maxAge = null,
            ) use (&$issued): SetCookieCollection {
                $issued = $value;
                return SetCookieCollection::create($name, $value)->withMaxAge($maxAge);
            });
        $session = new UserSessionMiddleware($this->config, $factory);
        $response = $this->guard->process(
            $request,
            new CallableRequestHandler(static function ($request) use (
                $session,
            ): \Psr\Http\Message\ResponseInterface {
                self::assertNull($request->getAttribute(UserCookieDecryptMiddleware::USER_COOKIE));
                self::assertArrayNotHasKey("USERSESSID", $request->getCookieParams());
                self::assertSame("preserved", $request->getCookieParams()["unrelated"]);
                // Successful password authentication supplies the framework user-session attribute.
                $request = $request->withAttribute(
                    AuthenticationMiddleware::AUTH_ATTRIBUTE,
                    (object) ["token" => "fictional-token"],
                );
                return $session->process(
                    $request,
                    new CallableRequestHandler(static fn() => new JsonResponse(["ok" => true])),
                );
            }),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($cookie, $issued);
        $newProof = $this->reader->read($this->request($issued));
        self::assertNotNull($newProof);
        self::assertTrue($this->authority->permits($newProof));
        self::assertFalse($this->authority->permits($proof));
    }
    public function testExplicitPasswordSigninEndsPriorReferenceAndStartsFreshCookie(): void
    {
        $cookie = $this->cookie();
        $request = $this->request($cookie, "/auth/")
            ->withMethod("POST")
            ->withAttribute(
                AuthenticationMiddleware::AUTH_ATTRIBUTE,
                (object) ["token" => "fictional-token"],
            );
        $old = $this->reader->read($request);
        $reference = $this->authority->current($old);
        $other = $this->reader->read($this->request($this->cookie()));
        $this->authority->current($other);
        $issued = null;
        $factory = $this->createMock(HttpCookieFactory::class);
        $factory
            ->expects(self::once())
            ->method("make")
            ->willReturnCallback(static function (
                string $name,
                ?string $value = null,
                ?int $maxAge = null,
            ) use (&$issued): SetCookieCollection {
                $issued = $value;
                return SetCookieCollection::create($name, $value)->withMaxAge($maxAge);
            });
        $middleware = new \Application\Http\Middleware\StartLoginSessionMiddleware(
            $this->reader,
            $this->authority,
            new UserSessionMiddleware($this->config, $factory),
        );
        $response = $middleware->process(
            $request,
            new CallableRequestHandler(static fn() => new JsonResponse([])),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($cookie, $issued);
        self::assertFalse($this->authority->activeReference($reference->id, $reference->user));
        self::assertTrue($this->authority->permits($other));
        self::assertTrue($this->authority->permits($this->reader->read($this->request($issued))));
    }
    public function testUnauthenticatedStartDoesNotRevokeAnExistingSignIn(): void
    {
        $request = $this->request($this->cookie(), "/auth/");
        $proof = $this->reader->read($request);
        $this->authority->current($proof);
        $factory = $this->createMock(HttpCookieFactory::class);
        $factory->expects(self::never())->method("make");
        $middleware = new \Application\Http\Middleware\StartLoginSessionMiddleware(
            $this->reader,
            $this->authority,
            new UserSessionMiddleware($this->config, $factory),
        );
        try {
            $middleware->process($request, new CallableRequestHandler(static fn() => new JsonResponse([])));
            self::fail();
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertTrue($this->authority->permits($proof));
        }
    }
    public function testInvalidCookieCannotPreserveAnUpstreamTokenAttribute(): void
    {
        $response = $this->guard->process(
            $this->request("invalid"),
            new CallableRequestHandler(static function ($request): JsonResponse {
                self::assertNull($request->getAttribute(UserCookieDecryptMiddleware::USER_COOKIE));
                return new JsonResponse([]);
            }),
        );
        self::assertSame(200, $response->getStatusCode());
    }
    public function testStateReadFailureStopsProtectedRequestWithPrivateResponse(): void
    {
        $request = $this->request($this->cookie());
        $this->f->connection->pdo->exec("DROP TABLE sis_login_sessions");
        $response = new SisPrivacyMiddleware()->process(
            $request,
            new CallableRequestHandler(
                fn($request) => $this->guard->process(
                    $request,
                    new CallableRequestHandler(static function (): never {
                        self::fail("Protected handler ran without authority storage.");
                    }),
                ),
            ),
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString("no-store", $response->getHeaderLine("Cache-Control"));
        self::assertStringNotContainsString("sis_login_sessions", (string) $response->getBody());
    }
}
