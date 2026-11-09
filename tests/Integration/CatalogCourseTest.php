<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Application\Http\Controller\SisRegistrationController;
use Application\Http\Middleware\SisPrivacyMiddleware;
use Application\Http\Middleware\WorkspaceJsonBodyMiddleware;
use Domain\Registration\Service\RegistrarCatalogService;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;

final class CatalogCourseTest extends TestCase
{
    public function testHyphenatedCourseCodeSavesAndCanBeFoundInCatalog(): void
    {
        $fixture = new SisFixture();
        $controller = new SisRegistrationController($fixture->service, new RegistrarCatalogService(
            $fixture->registration,
            $fixture->access,
            $fixture->transactions,
            $fixture->audit
        ));
        $request = new ServerRequest([], [], '/sis/courses/', 'POST')
            ->withHeader('Content-Type', 'application/json')->withBody(new Stream('php://temp', 'w+'));
        $request->getBody()->write(json_encode(['code' => 'MATH-101', 'title' => 'College Algebra',
            'credits_milli' => 3000], JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $endpoint = new CallableRequestHandler($controller->course(...));
        $json = new CallableRequestHandler(static fn ($request) =>
            new WorkspaceJsonBodyMiddleware()->process($request, $endpoint));
        $response = new SisPrivacyMiddleware()->process($request, $json);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $stored = $fixture->sql->one('sis_courses', ['id' => $result['id']]);
        self::assertSame('MATH-101', $stored['code']);
        self::assertSame('College Algebra', $stored['title']);
        self::assertSame(3000, (int) $stored['credits_milli']);
        $directory = new SqlWorkspaceRepository($fixture->sql, $fixture->gradeCatalog())->records('courses', null, 'MATH-101', null);
        self::assertCount(1, $directory['items']);
        self::assertSame('MATH-101', $directory['items'][0]['code']);
    }
}
