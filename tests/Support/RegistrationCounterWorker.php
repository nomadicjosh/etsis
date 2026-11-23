<?php

declare(strict_types=1);

// Two bounded workers share only fictional counters and lock stripes, never the application database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
\Gettext\TranslatorFunctions::register(new \Gettext\Translator());
$directory = $argv[1];
$worker = $argv[2];
$config = \Qubus\Config\Collection::factory([]);
$config->setConfigKey('throttle', ['registration' => ['max_attempts' => 5, 'ttl' => 600]]);
$config->setConfigKey('app', ['crypto_key' => str_repeat('fictional-key-', 4)]);
$adapter = new \League\Flysystem\Local\LocalFilesystemAdapter(
    $directory . '/cache',
    \League\Flysystem\UnixVisibility\PortableVisibilityConverter::fromArray([
        'file' => ['public' => 0600, 'private' => 0600],
        'dir' => ['public' => 0700, 'private' => 0700],
    ]),
    LOCK_EX,
    \League\Flysystem\Local\LocalFilesystemAdapter::DISALLOW_LINKS,
);
$cache = new \Qubus\Cache\Psr6\ItemPool(
    new \Qubus\Cache\Adapter\FileSystemCacheAdapter(new \League\Flysystem\Filesystem($adapter)),
);
$middleware = new \Application\Http\Middleware\PublicRegistrationThrottleMiddleware(
    $config,
    $cache,
    new \Infrastructure\Security\FileRegistrationCounterLock($directory . '/locks'),
);
file_put_contents($directory . '/ready-' . $worker, 'ready');
$deadline = microtime(true) + 3;
while (!is_file($directory . '/start')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The fictional registration workers did not start in time.');
    }
    usleep(10000);
}
$statuses = [];
$request = new \Qubus\Http\ServerRequest(['REMOTE_ADDR' => '192.0.2.10'], [], '/create/', 'POST')
    ->withHeader('Accept', 'application/json');
$handler = new \Qubus\Routing\Handlers\CallableRequestHandler(
    static fn () => new \Laminas\Diactoros\Response\JsonResponse(['accepted' => true]),
);
for ($attempt = 0; $attempt < 20; $attempt++) {
    $statuses[] = $middleware->process($request, $handler)->getStatusCode();
}
echo json_encode($statuses, JSON_THROW_ON_ERROR);
