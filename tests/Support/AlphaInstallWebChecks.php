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
$sql = $app->make(\Infrastructure\Persistence\SqlRecords::class);
$user = $sql->one("users", ["email" => "alpha.tester@example.invalid"]);
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
echo "Fictional registration and first administrator verified.\n";
