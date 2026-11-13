<?php

declare(strict_types=1);

// Separate process, fictional configuration, no institutional database or .env.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/etsis-console-' . bin2hex(random_bytes(6));
mkdir($fixture);
mkdir($fixture . '/config');
ini_set('session.save_path', $fixture);
foreach (glob($root . '/config/*.php') as $file) {
    copy($file, $fixture . '/config/' . basename($file));
}
foreach (['bootstrap', 'database', 'resources', 'public'] as $directory) {
    symlink($root . '/' . $directory, $fixture . '/' . $directory);
}
foreach (['APP_BASE_PATH' => $fixture, 'DB_CONNECTION' => 'sqlite', 'DB_DSN' => 'sqlite::memory:',
    'APP_ENV' => 'testing', 'APP_TIMEZONE' => 'UTC',
    'APP_BASE_URL' => 'https://campus.example.invalid'] as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
    chdir($root);
    if (($argv[1] ?? '') === 'check-course-route') {
        $_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/sis/courses/';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['_workspace_json' => json_encode(['code' => 'Math-101', 'title' => 'Algebra',
            'credits_milli' => 3000], JSON_THROW_ON_ERROR)];
    }
    if (in_array(($argv[1] ?? ''), ['check-portal', 'check-profile-command', 'check-login-sessions', 'check-moodle-resolution'], true)) {
        $_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/portal/';
        $_SERVER['HTTP_HOST'] = 'campus.example.invalid';
        $_SERVER['HTTPS'] = 'on';
    }
    $app = \Codefy\Framework\Helpers\get_fresh_bootstrap();
    if (($argv[1] ?? '') === 'check-early-webhook') {
        $app->bootstrapWith([\Codefy\Framework\Bootstrap\RegisterProviders::class]);
        $webhook = $app->make(\Application\Http\Controller\PaymentWebhookController::class);
        $response = $webhook->receive('{"amount_total":12345}', '');
        if ($response->getStatusCode() !== 400) {
            throw new RuntimeException('An unsigned early webhook was not rejected.');
        }
        $status = 0;
    } else {
        $kernel = $app->make(Application\Console\Kernel::class);
        if (($argv[1] ?? '') === 'check-course-route') {
            require_once __DIR__ . '/SisFixture.php';
            $sis = new SisFixture();
            $app->delegate(\Qubus\Expressive\Connection::class, static fn () => $sis->connection);
            $app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn () => $sis->access);
            $kernel->bootstrap();
            // Exercise route DI, firewall, CSRF validation, privacy and debug suppression.
            $app->router->baseMiddleware = ['sis.privacy', 'sis.json', 'firewall', 'csrf.protection',
                'bind.request', 'php.debugbar'];
            $request = new \Qubus\Http\ServerRequest([], [], '/sis/courses/', 'POST')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withHeader('Accept', 'application/json')
                ->withHeader('X-CSRF-Token', 'fictionalcsrftoken')
                ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
                    'fictionalcsrftoken')
                ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
            $request->getBody()->write(http_build_query($_POST));
            $request->getBody()->rewind();
            $response = $app->router->match($request);
            if ($response->getStatusCode() !== 201) {
                throw new RuntimeException('Course route expected HTTP 201 JSON, received HTTP '
                    . $response->getStatusCode() . ' with ' . $response->getHeaderLine('Content-Type'));
            }
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $course = $sis->sql->one('sis_courses', ['id' => $body['id']]);
            if ($course['code'] !== 'Math-101' || $course['title'] !== 'Algebra'
                || (int) $course['credits_milli'] !== 3000) {
                throw new RuntimeException('Course details were not persisted.');
            }
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-portal') {
            $kernel->bootstrap();
            require __DIR__ . '/PortalBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-profile-command') {
            $kernel->bootstrap();
            require __DIR__ . '/ProfileCommandBootChecks.php';
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-login-sessions') {
            require __DIR__.'/LoginSessionBootChecks.php';
            $status=0;
        } elseif (($argv[1] ?? '') === 'check-moodle-resolution') {
            require __DIR__.'/MoodleResolutionBootChecks.php';
            $status=0;
        } elseif (($argv[1] ?? '') === 'check-session-retention') {
            require __DIR__.'/SessionRetentionBootChecks.php';
            $status=0;
        } elseif (($argv[1] ?? '') === 'check-moodle') {
            $kernel->bootstrap();
            $app->configContainer->setConfigKey('moodle', ['enabled' => false]);
            $probe = $app->make(\Domain\Lms\Service\MoodleConnectionProbe::class);
            if (!$probe instanceof \Infrastructure\Lms\RestMoodleGateway || $probe->enabled()) {
                throw new RuntimeException('Moodle diagnostic provider failed to resolve in disabled mode.');
            }
            $command = new \Application\Console\Commands\MoodleCheckCommand($app, $probe);
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            if ($tester->execute([]) !== 0 || !str_contains($tester->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle diagnostic did not complete.');
            }
            $sync = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:moodle:sync']);
            if ($sync->execute(['--limit' => '21']) !== 2 || $sync->execute([]) !== 0 ||
                !str_contains($sync->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled section synchronization failed its command boundary.');
            }
            if ($kernel->call('sis:moodle:provision') !== 0) {
                throw new RuntimeException('The disabled Moodle provisioning command was not registered.');
            }
            $worker = $app->make(\Domain\Lms\Service\MoodleProvisioningWorker::class);
            $provision = new \Symfony\Component\Console\Tester\CommandTester(new \Application\Console\Commands\MoodleProvisionCommand($app, $worker));
            if ($provision->execute([]) !== 0 || !str_contains($provision->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle worker did not resolve without network or queue access.');
            }
            $reader = $app->make(\Domain\Lms\Service\MoodleRecordReader::class);
            $inspect = new \Symfony\Component\Console\Tester\CommandTester(
                new \Application\Console\Commands\MoodleInspectCommand($app, $reader),
            );
            if ($inspect->execute(['resource' => 'user', 'remote_id' => '123']) !== 0 ||
                !str_contains($inspect->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle record reader did not resolve without network access.');
            }
            $instanceReader = $app->make(\Domain\Lms\Service\MoodleInstanceReader::class);
            $instance = new \Symfony\Component\Console\Tester\CommandTester(
                new \Application\Console\Commands\MoodleInstanceCommand($app, $instanceReader),
            );
            if ($instance->execute([]) !== 0 || !str_contains($instance->getDisplay(), 'disabled')) {
                throw new RuntimeException('Disabled Moodle instance reader did not resolve without network access.');
            }
            $status = 0;
        } elseif (($argv[1] ?? '') === 'check-authentication') {
            $kernel->bootstrap();
            if (!$app->get(\Codefy\Framework\Auth\Sentinel::class) instanceof \Codefy\Framework\Auth\Auth
                || !$app->get(\Codefy\Framework\Auth\Rbac\Resource\StorageResource::class)
                    instanceof \Infrastructure\Security\Rbac\SqlStorageResource) {
                throw new RuntimeException('Framework authentication must use database RBAC storage.');
            }
            $middleware = new \Qubus\Routing\Route\InjectorMiddlewareResolver($app)->resolve('user.authenticate');
            $handler = new \Qubus\Routing\Handlers\CallableRequestHandler(static function (): never {
                throw new RuntimeException('Unauthenticated request reached the protected handler.');
            });
            $response = $middleware->process(new \Qubus\Http\ServerRequest([], [], '/auth/', 'POST'), $handler);
            if ($response->getStatusCode() !== 302 || $response->getHeaderLine('Location') !== '/login/?notice=credentials') {
                throw new RuntimeException('Authentication middleware did not reject missing credentials.');
            }
            $status = 0;
        } else {
            $status = $kernel->call($argv[1] ?? 'migrate:status');
        }
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($fixture);
}
exit($status);
