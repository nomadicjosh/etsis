<?php

declare(strict_types=1);

// Isolated public-response fixture: no institutional configuration, keys, database or account creation.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = sys_get_temp_dir() . '/etsis-registration-page-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/bootstrap', 0700);
mkdir($root . '/config', 0700);
file_put_contents(
    $root . '/bootstrap/app.php',
    '<?php return new \\Codefy\\Framework\\Application(["basePath" => dirname(__DIR__)]);',
);
file_put_contents($root . '/config/app.php', '<?php return ["locale_domain" => "etsis"];');
chdir($root);
\Gettext\TranslatorFunctions::register(new \Gettext\Translator());
try {
    $config = \Qubus\Config\Collection::factory([]);
    $config->setConfigKey('throttle', ['registration' => ['max_attempts' => 1, 'ttl' => 600]]);
    $config->setConfigKey('app', ['crypto_key' => str_repeat('fictional-key-', 4)]);
    $config->setConfigKey('auth', ['login_route' => 'staff-access']);
    $cache = new \Qubus\Cache\InMemoryCache();
    $lock = new \Infrastructure\Security\FileRegistrationCounterLock($root . '/locks');
    $middleware = new \Application\Http\Middleware\PublicRegistrationThrottleMiddleware($config, $cache, $lock);
    $request = new \Qubus\Http\ServerRequest(['REMOTE_ADDR' => '192.0.2.10'], [], '/create/', 'POST')
        ->withHeader('Accept', 'text/html')->withParsedBody([
            'email' => 'fictional-private@example.invalid', 'password' => 'Fictional-private-password',
        ]);
    $handler = new \Qubus\Routing\Handlers\CallableRequestHandler(
        static fn () => new \Laminas\Diactoros\Response\JsonResponse(['accepted' => true]),
    );
    if (($argv[1] ?? '') === 'busy') {
        $identifier = hash_hmac(
            'sha256',
            'public-registration:' . inet_pton('192.0.2.10'),
            str_repeat('fictional-key-', 4),
        );
        $lock->run($identifier, static function () use ($middleware, $request, $handler, &$response): void {
            $response = $middleware->process($request, $handler);
        });
    } else {
        $middleware->process($request, $handler);
        $response = $middleware->process($request, $handler);
    }
    fwrite(STDERR, json_encode(['status' => $response->getStatusCode(), 'headers' => array_map(
        static fn (array $values): string => implode(', ', $values),
        $response->getHeaders(),
    )], JSON_THROW_ON_ERROR));
    echo $response->getBody();
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    chdir(sys_get_temp_dir());
    rmdir($root);
}
