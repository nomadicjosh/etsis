<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . "/vendor/autoload.php";
$fixture = $argv[1];
ini_set("session.save_path", $fixture . "/storage");
$_SERVER["DOCUMENT_ROOT"] = $fixture . "/public";
$_SERVER["SCRIPT_NAME"] = "/index.php";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"] = "/portal/";
$_SERVER["HTTP_HOST"] = "campus.example.invalid";
$_SERVER["HTTPS"] = "on";
$app = \Codefy\Framework\Helpers\get_fresh_bootstrap();
$app->make(\Application\Console\Kernel::class)->bootstrap();
$app->router->baseMiddleware = [
    "sis.privacy",
    "csrf.protection",
    "user.cookie.decrypt",
    "auth.session.authority",
    "bind.request",
    "php.debugbar",
];
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
$request = static function (
    string $path,
    string $method = "GET",
    array $body = [],
): \Qubus\Http\ServerRequest {
    return new \Qubus\Http\ServerRequest([], [], "https://campus.example.invalid" . $path, $method)
        ->withParsedBody($body)
        ->withHeader("X-CSRF-Token", "fictionaltesttoken")
        ->withAttribute(
            \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
            "fictionaltesttoken",
        );
};
$password = bin2hex(random_bytes(32));
$sql = $app->make(\Infrastructure\Persistence\SqlRecords::class);
$missingCsrf = $app->router->match(
    $request('/create/', 'POST', ['email' => 'rejected@example.invalid'])
        ->withoutHeader('X-CSRF-Token'),
);
if ($missingCsrf->getStatusCode() !== 403 || $sql->rows('users', limit: 1) !== []) {
    throw new RuntimeException('Fresh registration did not reject a missing CSRF proof before writing.');
}
// Public policy uses actual providers/routes; rejected requests preserve all existing identity/history facts.
$app->configContainer->setConfigKey('throttle', ['registration' => ['max_attempts' => 100, 'ttl' => 600]]);
$policyTables = ['users', 'sis_persons', 'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
$policyBefore = array_map($sql->rows(...), $policyTables);
$assertPolicy = static function (
    \Psr\Http\Message\ResponseInterface $response,
    int $expected,
) use (
    $sql,
    $policyTables,
    $policyBefore
): void {
    if (
        $response->getStatusCode() !== $expected || $response->hasHeader('phpdebugbar-id')
        || !str_contains($response->getHeaderLine('Cache-Control'), 'private, no-store')
        || $policyBefore !== array_map($sql->rows(...), $policyTables)
    ) {
        throw new RuntimeException('The public account policy lost its status, privacy or no-write boundary.');
    }
    foreach (['PRIVATE', 'rejected@example.invalid', 'private-test-password'] as $value) {
        if (str_contains((string) $response->getBody(), $value)) {
            throw new RuntimeException('A public account policy response exposed submitted details.');
        }
    }
};
$policyBody = ['email' => 'rejected@example.invalid', 'password' => 'private-test-password',
    'return' => 'https://example.invalid/PRIVATE', 'program' => '01K00000000000000000000001'];
$app->configContainer->setConfigKey('registration', ['enabled' => false]);
$assertPolicy($app->router->match($request('/register/')), 403);
$assertPolicy($app->router->match($request('/create/', 'POST', $policyBody)), 403);
$policyGet = $request('/login/')->withHeader('Accept', 'text/html');
$policyLogin = $app->router->match($policyGet);
if (
    $policyLogin->getStatusCode() !== 200
    || str_contains((string) $policyLogin->getBody(), 'Create your campus account')
) {
    throw new RuntimeException('Closing public signup hid existing sign-in or retained its signup invitation.');
}
$app->configContainer->setConfigKey('registration', ['enabled' => 'PRIVATE invalid flag']);
$assertPolicy($app->router->match($request('/register/')), 503);
$unavailable = $app->router->match($request('/create/', 'POST', $policyBody));
$assertPolicy($unavailable, 503);
if (
    $unavailable->getHeaderLine('Retry-After') !== '5'
    || $app->router->match($policyGet)->getStatusCode() !== 200
) {
    throw new RuntimeException('Invalid signup policy prevented existing sign-in or lost its retry header.');
}
$app->configContainer->setConfigKey('registration', ['enabled' => true, 'email_domains' => ['institution.example']]);
$disallowedEmails = ['rejected@example.invalid', 'student@sub.institution.example',
    'student@institution.example.evil.invalid', []];
foreach ($disallowedEmails as $email) {
    $response = $app->router->match($request('/create/', 'POST', array_replace($policyBody, ['email' => $email])));
    $assertPolicy($response, 422);
}
$assertPolicy($app->router->match($request('/create/', 'POST', $policyBody)
    ->withoutHeader('X-CSRF-Token')), 403);
$app->configContainer->setConfigKey('registration', ['enabled' => true, 'email_domains' => ['EXAMPLE.INVALID']]);
$registerPage = $app->router->match($request('/register/?program=01K00000000000000000000001')
    ->withQueryParams(['program' => '01K00000000000000000000001'])->withHeader('Accept', 'text/html'));
$registrationDocument = new DOMDocument();
$registrationDocument->loadHTML(
    (string) $registerPage->getBody(),
    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
);
$acceptedDomains = $registrationDocument->getElementById('campus-registration-domains');
if (
    $registerPage->getStatusCode() !== 200
    || !str_contains($acceptedDomains?->textContent ?? '', 'example.invalid')
) {
    throw new RuntimeException('The actual registration page omitted its accepted email domains.');
}
$response = $app->router->match(
    $request("/create/", "POST", [
        "username" => "alpha_tester",
        "first_name" => "Alex",
        "last_name" => "Example",
        "email" => "alpha.tester@example.invalid",
        "password" => $password,
        "role" => "super",
    ]),
);
if ($response->getStatusCode() !== 302 || !str_contains($response->getHeaderLine("Location"), "/login/")) {
    throw new RuntimeException("Fresh account registration did not complete.");
}
$user = $sql->one("users", ["email" => "alpha.tester@example.invalid"]);
$userId = new \Domain\Shared\ValueObject\EntityId($user['user_id']);
$people = $sql->rows('sis_persons', ['owner_user_id' => $userId->value], 2);
if (count($people) !== 1 || $people[0]['given_name'] !== 'Alex' || $people[0]['family_name'] !== 'Example') {
    throw new RuntimeException('Fresh registration did not retain one canonical person and explicit account ULID.');
}
$originalEvents = $sql->fetch('SELECT * FROM sis_history_events ORDER BY id', limit: 20);
$app->configContainer->setConfigKey('registration', ['enabled' => false]);
$access = $app->make(\Domain\Access\Repository\AccessRepository::class);
if ($access->anySuper()) {
    throw new RuntimeException("Public registration granted a privileged role.");
}
$command = $app->make(\Application\Console\Commands\BootstrapAccessCommand::class);
new \Symfony\Component\Console\Application()->add($command);
$tester = new \Symfony\Component\Console\Tester\CommandTester($command);
$tester->setInputs(["alpha.tester@example.invalid", "yes"]);
if ($tester->execute([]) !== 0 || !$access->isSuper($user["user_id"])) {
    throw new RuntimeException("Fresh interactive administrator bootstrap did not complete.");
}
$login = $app->router->match(
    $request("/auth/", "POST", ["email" => "alpha.tester@example.invalid", "password" => $password]),
);
$cookie = null;
foreach ($login->getHeader("Set-Cookie") as $header) {
    $value = \Qubus\Http\Cookies\SetCookieCollection::fromSetCookieString($header);
    if ($value->getName() === "USERSESSID") {
        $cookie = $value->getValue();
    }
}
if ($login->getStatusCode() !== 302 || !$cookie) {
    throw new RuntimeException("Fresh administrator sign-in failed.");
}
$admin = $app->router->match($request("/admin/")->withCookieParams(["USERSESSID" => $cookie]));
if ($admin->getStatusCode() !== 200 || !str_contains((string) $admin->getBody(), "Campus workspace")) {
    throw new RuntimeException("Fresh administrator could not open the backend.");
}
if (!str_contains($admin->getHeaderLine("Cache-Control"), "no-store")) {
    throw new RuntimeException("Fresh administrator response did not remain private.");
}
if ($tester->execute(["user_id" => $user["user_id"]], ["interactive" => false]) !== 1) {
    throw new RuntimeException("The initial administrator command remained usable after bootstrap.");
}
foreach (['GET', 'POST'] as $method) {
    $signedInRegistration = $request(
        $method === 'GET' ? '/register/' : '/create/',
        $method,
        ['program' => '01K00000000000000000000001', 'email' => 'PRIVATE ignored']
    )
        ->withQueryParams(['program' => '01K00000000000000000000001'])
        ->withCookieParams(['USERSESSID' => $cookie]);
    $redirected = $app->router->match($signedInRegistration);
    if (
        $redirected->getStatusCode() !== 302
        || !str_contains($redirected->getHeaderLine('Location'), '/portal/apply/?program=')
        || $originalEvents !== $sql->fetch('SELECT * FROM sis_history_events ORDER BY id', limit: 20)
    ) {
        throw new RuntimeException('Closed signup blocked an authenticated application continuation or changed facts.');
    }
}
$subject = $app->router->match($request('/sis/catalog/subjects/save/', 'POST', [
    'code' => 'TEST', 'title' => 'Fictional subject', 'active' => true,
])->withCookieParams(['USERSESSID' => $cookie]));
if ($subject->getStatusCode() !== 200) {
    throw new RuntimeException('Fresh catalog flag creation failed.');
}
$subjectRow = json_decode((string) $subject->getBody(), true, 8, JSON_THROW_ON_ERROR);
$subject = $app->router->match($request('/sis/catalog/subjects/save/', 'POST', [
    'id' => $subjectRow['id'], 'code' => 'TEST', 'title' => 'Fictional subject', 'active' => false,
    'revision' => $subjectRow['revision'],
])->withCookieParams(['USERSESSID' => $cookie]));
if (
    $subject->getStatusCode() !== 200 ||
    json_decode((string) $subject->getBody(), true, 8, JSON_THROW_ON_ERROR)['active'] !== false
) {
    throw new RuntimeException('Fresh catalog flag update failed.');
}
if ($originalEvents !== $sql->fetch('SELECT * FROM sis_history_events ORDER BY id', limit: 20)) {
    throw new RuntimeException('Authentication or reference edits changed recorded profile history.');
}
$verified = $app->make(\Domain\Shared\EventSourcing\HistoricalIntegrityVerifier::class)
    ->verify(streamLimit: 50, eventLimit: 200);
if (!$verified->complete || $verified->events === 0 || $sql->tableExists('event_store')) {
    throw new RuntimeException('Fresh profile history or single-journal migration did not verify.');
}
echo "Fictional registration and first administrator verified.\n";
