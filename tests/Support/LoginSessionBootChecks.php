<?php
declare(strict_types=1);
// Included only by the isolated bootstrap process; no institutional .env, database or mail.
require_once __DIR__ . "/SisFixture.php";
$sis = new SisFixture();
$sis->clock->time = new DateTimeImmutable("now", new DateTimeZone("UTC"));
foreach (["token", "password"] as $column) {
    $sis->connection->pdo->exec("ALTER TABLE users ADD COLUMN " . $column . " VARCHAR(191)");
}
$password = "Fictional sign-in password only";
$sis->sql->update(
    "users",
    [
        "token" => "fictional-browser-token",
        "password" => password_hash($password, PASSWORD_BCRYPT, ["cost" => 4]),
        "email" => "fictional@example.invalid",
    ],
    ["user_id" => $sis->access->actor->value],
);
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
// The isolated probe is not a CMS page; keep the unrelated catch-all router out of this fixture.
$app->booting(static function () use ($app): void {
    $app->configContainer->getConfigKey("vihzhuo");
    $app->configContainer->setConfigKey("vihzhuo", ["router" => ["use_router" => false]]);
});
$kernel->bootstrap();
// Several independent sign-in scenarios share this fixture's one address; production remains five attempts.
$app->configContainer->setConfigKey("throttle", ["max_attempts" => 10, "ttl" => 600]);
$app->configContainer->setConfigKey("app", [
    "crypto_key" => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
]);
$app->router->baseMiddleware = [
    "sis.privacy",
    "moodle.session.channel",
    "csrf.protection",
    "user.cookie.decrypt",
    "auth.session.authority",
    "bind.request",
    "php.debugbar",
];
$app->router->get("/portal/session-probe/", static function () use (
    $app,
): \Psr\Http\Message\ResponseInterface {
    $proof = $app
        ->make(\Application\Http\Security\LoginCookieReader::class)
        ->read(\Codefy\Framework\Http\RequestContext::get());
    if ($proof === null) {
        throw new \Domain\Shared\Exception\AccessDeniedException("Sign in required.");
    }
    $session = $app->make(\Domain\User\Service\LoginSessionAuthority::class)->current($proof);
    return new \Laminas\Diactoros\Response\JsonResponse(["reference" => $session->id->value]);
});
$request = static function (
    string $path,
    string $method = "GET",
    ?string $cookie = null,
    array $body = [],
    bool $csrf = true,
): \Qubus\Http\ServerRequest {
    $request = new \Qubus\Http\ServerRequest(
        [],
        [],
        "https://campus.example.invalid" . $path,
        $method,
    )->withParsedBody($body);
    if ($cookie !== null) {
        $request = $request->withCookieParams(["USERSESSID" => $cookie]);
    }
    if ($csrf) {
        $request = $request
            ->withHeader("X-CSRF-Token", "fictionalcsrftoken")
            ->withAttribute(
                \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
                "fictionalcsrftoken",
            );
    }
    return $request;
};
$cookieFrom = static function (\Psr\Http\Message\ResponseInterface $response): ?string {
    foreach ($response->getHeader("Set-Cookie") as $header) {
        $cookie = \Qubus\Http\Cookies\SetCookieCollection::fromSetCookieString($header);
        if ($cookie->getName() === "USERSESSID") {
            return $cookie->getValue();
        }
    }
    return null;
};
$credentials = ["email" => "fictional@example.invalid", "password" => $password];
$missing = $app->router->match($request("/auth/", "POST", body: $credentials, csrf: false));
if ($missing->getStatusCode() !== 403 || $sis->sql->rows("sis_login_sessions") !== []) {
    throw new RuntimeException(
        "Login expected CSRF rejection 403; received HTTP " . $missing->getStatusCode() . ".",
    );
}
$login = $app->router->match($request("/auth/", "POST", body: $credentials));
$first = $cookieFrom($login);
if (
    $login->getStatusCode() !== 302 ||
    !$first ||
    !str_contains($login->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("The native sign-in flow failed.");
}
$probe = $app->router->match($request("/portal/session-probe/", cookie: $first));
if ($probe->getStatusCode() !== 200) {
    throw new RuntimeException("The real Gate could not register its sign-in reference.");
}
$firstRef = json_decode((string) $probe->getBody(), true, flags: JSON_THROW_ON_ERROR)["reference"];

// This fixture supplies CSRF proof directly; initialize the view helper without changing that proof.
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
// Exercise direct launch through real providers, Gate, CSRF and route middleware.
$app->configContainer->setConfigKey("moodle", [
    "enabled" => true,
    "launch_enabled" => true,
    "launch_secret" => str_repeat("c", 64),
    "url" => "https://learning.example.invalid",
]);
$person = new \Domain\Person\Person(
    \Domain\Shared\ValueObject\EntityId::generate(),
    $sis->access->actor,
    "Alex",
    "Example",
    new \Domain\Person\ValueObject\InstitutionalId("FICTIONAL-SSO"),
);
$sis->admissions->addPerson($person);
$binding = new \Domain\Lms\ValueObject\MoodleBinding(
    \Domain\Shared\ValueObject\EntityId::generate(),
    hash("sha256", "https://learning.example.invalid"),
    hash("sha256", str_repeat("a", 32)),
    $sis->access->actor,
    "2026-11-12T12:00:00Z",
);
$app->make(\Domain\Lms\Repository\MoodleBindingRepository::class)->add(
    $binding,
    "fictional-encrypted-review",
);
$app->make(\Domain\Lms\Repository\MoodlePersonLinkRepository::class)->add(
    new \Domain\Lms\ValueObject\MoodlePersonLink(
        \Domain\Shared\ValueObject\EntityId::generate(),
        $binding->id,
        $person->id,
        new \Domain\Lms\ValueObject\MoodleExternalId("123"),
        $sis->access->actor,
        "2026-11-12T12:00:00Z",
    ),
    "fictional-encrypted-review",
);
if (
    $app->router->match($request("/portal/moodle/"))->getStatusCode() !== 302 ||
    $app->router->match($request("/portal/moodle/", cookie: $first))->getStatusCode() !== 200
) {
    throw new RuntimeException("The Moodle page did not enforce the signed-in owner.");
}
if (
    $app->router->match($request("/portal/moodle/launch/", "POST", $first, csrf: false))->getStatusCode() !==
        403 ||
    $sis->sql->rows("sis_moodle_launches") !== []
) {
    throw new RuntimeException("The Moodle browser launch lost CSRF protection.");
}
$handoff = $app->router->match(
    $request("/portal/moodle/launch/", "POST", $first, ["moodle_user_id" => "456"]),
);
if (
    $handoff->getStatusCode() !== 200 ||
    !str_contains($handoff->getHeaderLine("Cache-Control"), "no-store") ||
    !str_contains(
        $handoff->getHeaderLine("Content-Security-Policy"),
        "form-action https://learning.example.invalid",
    ) ||
    !preg_match('/name="ticket" value="([a-f0-9]{64})"/', (string) $handoff->getBody(), $match)
) {
    throw new RuntimeException("The private Moodle POST handoff failed.");
}
if ($sis->sql->rows("sis_moodle_launches")[0]["moodle_user_id"] !== "123") {
    throw new RuntimeException("Browser input changed the launch identity.");
}
$channelRequest = static function (array $data) use ($sis): \Qubus\Http\ServerRequest {
    $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $time = (string) $sis->clock->now()->getTimestamp();
    $nonce = str_repeat("d", 64);
    $stream = new \Laminas\Diactoros\Stream("php://temp", "w+");
    $stream->write($body);
    $stream->rewind();
    return new \Qubus\Http\ServerRequest([], [], "/sis/moodle/session-channel/", "POST", $stream, [
        "Content-Type" => "application/json",
        "X-Etsis-Time" => $time,
        "X-Etsis-Nonce" => $nonce,
        "X-Etsis-Signature" => hash_hmac(
            "sha256",
            "etsis-moodle/v1\nrequest\n$time\n$nonce\n$body",
            hex2bin(str_repeat("c", 64)),
        ),
    ]);
};
$exchange = $app->router->match(
    $channelRequest([
        "op" => "exchange",
        "ticket" => $match[1],
        "instance_hash" => $binding->instanceFingerprint,
    ]),
);
$grant = json_decode((string) $exchange->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $exchange->getStatusCode() !== 200 ||
    ($grant["active"] ?? false) !== true ||
    $grant["moodle_user_id"] !== "123" ||
    $exchange->getHeaderLine("X-Etsis-Signature") === "" ||
    !str_contains($exchange->getHeaderLine("Cache-Control"), "no-store") ||
    str_contains((string) $exchange->getBody(), "phpdebugbar")
) {
    throw new RuntimeException("The machine channel did not authenticate independently of browser CSRF.");
}
$grantCheck = [
    "op" => "check",
    "grant_id" => $grant["grant_id"],
    "moodle_user_id" => "123",
    "instance_hash" => $binding->instanceFingerprint,
];

$failed = $app->router->match(
    $request("/auth/", "POST", $first, [
        "email" => "fictional@example.invalid",
        "password" => "wrong fictional password",
    ]),
);
if (
    $failed->getStatusCode() !== 302 ||
    $app->router->match($request("/portal/session-probe/", cookie: $first))->getStatusCode() !== 200
) {
    throw new RuntimeException("Failed authentication ended a valid sign-in.");
}

if ($failed->getStatusCode() !== 302 || $failed->getHeaderLine("Location") !== "/login/?notice=credentials") {
    throw new RuntimeException("Wrong credentials did not produce the fixed sign-in notice.");
}
$failurePage = $app->router->match($request("/login/")->withQueryParams(["notice" => "credentials"]));
if (
    $failurePage->getStatusCode() !== 200 ||
    !str_contains((string) $failurePage->getBody(), "Check your email address and password") ||
    !str_contains((string) $failurePage->getBody(), 'role="alert"') ||
    str_contains((string) $failurePage->getBody(), "wrong fictional password")
) {
    throw new RuntimeException("Sign-in rejection did not render accessible, private feedback.");
}
$unknown = $app->router->match(
    $request(
        "/auth/",
        "POST",
        body: [
            "email" => "absent@example.invalid",
            "password" => "wrong fictional password",
            "continue" => "apply",
        ],
    ),
);
if (
    $unknown->getStatusCode() !== 302 ||
    $unknown->getHeaderLine("Location") !== "/login/?notice=credentials&continue=apply"
) {
    throw new RuntimeException(
        "Unknown identities must receive the same notice and retain only the allowed continuation.",
    );
}

$missing = $app->router->match($request("/logout/", "POST", $first, csrf: false));
if (
    $missing->getStatusCode() !== 403 ||
    (int) $sis->sql->one("sis_login_sessions", ["id" => $firstRef])["revoked"] !== 0
) {
    throw new RuntimeException("Logout did not retain CSRF protection.");
}
$logout = $app->router->match($request("/logout/", "POST", $first));
if (
    $logout->getStatusCode() !== 302 ||
    (int) $sis->sql->one("sis_login_sessions", ["id" => $firstRef])["revoked"] !== 1
) {
    throw new RuntimeException("Logout did not revoke its server-side reference.");
}

$ended = $app->router->match($channelRequest($grantCheck));
if (json_decode((string) $ended->getBody(), true, flags: JSON_THROW_ON_ERROR) !== ["active" => false]) {
    throw new RuntimeException("Logging out did not end the linked Moodle grant.");
}

if ($app->router->match($request("/portal/session-probe/", cookie: $first))->getStatusCode() !== 403) {
    throw new RuntimeException("A replayed logout cookie was accepted.");
}
$relogin = $app->router->match($request("/auth/", "POST", $first, $credentials));
$second = $cookieFrom($relogin);
if (
    !$second ||
    $second === $first ||
    $app->router->match($request("/portal/session-probe/", cookie: $second))->getStatusCode() !== 200
) {
    throw new RuntimeException("Password re-sign-in did not produce a new usable envelope.");
}
$reauth = $app->router->match($request("/auth/", "POST", $second, $credentials));
$third = $cookieFrom($reauth);
if (
    !$third ||
    $third === $second ||
    $app->router->match($request("/portal/session-probe/", cookie: $second))->getStatusCode() !== 403 ||
    $app->router->match($request("/portal/session-probe/", cookie: $third))->getStatusCode() !== 200
) {
    throw new RuntimeException("Explicit re-authentication did not end the earlier reference.");
}
$other = $sis->user();
$sis->sql->update(
    "users",
    [
        "token" => "fictional-second-user-token",
        "password" => password_hash($password, PASSWORD_BCRYPT, ["cost" => 4]),
        "email" => "other@example.invalid",
    ],
    ["user_id" => $other->value],
);
$switch = $app->router->match(
    $request("/auth/", "POST", $third, ["email" => "other@example.invalid", "password" => $password]),
);
$switched = $cookieFrom($switch);
if (
    !$switched ||
    $app->router->match($request("/portal/session-probe/", cookie: $third))->getStatusCode() !== 403
) {
    throw new RuntimeException("Account switching left the prior browser reference active.");
}
$switchedProbe = $app->router->match($request("/portal/session-probe/", cookie: $switched));
if ($switchedProbe->getStatusCode() !== 200) {
    throw new RuntimeException("The switched account could not establish its own reference.");
}
$switchedId = json_decode((string) $switchedProbe->getBody(), true, flags: JSON_THROW_ON_ERROR)["reference"];
if ($sis->sql->one("sis_login_sessions", ["id" => $switchedId])["user_id"] !== $other->value) {
    throw new RuntimeException("A sign-in reference crossed account ownership.");
}
$before = count($sis->sql->rows("sis_login_sessions"));
$anonymous = $app->router->match($request("/logout/", "POST"));
if ($anonymous->getStatusCode() !== 302 || count($sis->sql->rows("sis_login_sessions")) !== $before) {
    throw new RuntimeException("Anonymous logout changed sign-in records.");
}
