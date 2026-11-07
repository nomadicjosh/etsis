<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Application\Http\Controller\SisCatalogController;
use Application\Http\Middleware\SisPrivacyMiddleware;
use Application\Http\Middleware\WorkspaceJsonBodyMiddleware;
use Domain\Catalog\Service\CatalogService;
use Infrastructure\Persistence\Repository\DbalCatalogRepository;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Handlers\CallableRequestHandler;
use Psr\Http\Message\ResponseInterface;

final class CatalogTermTest extends TestCase
{
    private function submit(SisFixture $fixture, array $data): ResponseInterface
    {
        $controller = new SisCatalogController(new CatalogService(
            new DbalCatalogRepository($fixture->connection->queryBuilder()), $fixture->access));
        $request = new ServerRequest([], [], '/sis/terms/', 'POST')
            ->withHeader('Content-Type', 'application/json')->withBody(new Stream('php://temp', 'w+'));
        $request->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        $request->getBody()->rewind();
        $endpoint = new CallableRequestHandler($controller->term(...));
        $json = new CallableRequestHandler(static fn ($request) =>
            new WorkspaceJsonBodyMiddleware()->process($request, $endpoint));

        return new SisPrivacyMiddleware()->process($request, $json);
    }

    private function valid(): array
    {
        return ['code' => '2027SP', 'starts_on' => '2027-01-11', 'ends_on' => '2027-05-14',
            'add_drop_ends_on' => '2027-01-22'];
    }

    public function testWorkspaceTermPayloadIsPersistedAndCodeCaseIsNormalized(): void
    {
        $fixture = new SisFixture();
        $response = $this->submit($fixture, array_replace($this->valid(), ['code' => ' 2027sp ']));
        self::assertSame(201, $response->getStatusCode());
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $stored = $fixture->sql->one('sis_terms', ['id' => $result['id']]);
        self::assertSame('2027SP', $stored['code']);
        self::assertSame('2027-01-11', $stored['starts_on']);
        self::assertSame('2027-05-14', $stored['ends_on']);
        self::assertSame('2027-01-22', $stored['add_drop_ends_on']);
    }

    public function testInvalidDatesAndCodesReturnSpecificErrorsWithoutWriting(): void
    {
        $fixture = new SisFixture();
        foreach ([['code', 'Spring 2027'], ['starts_on', '2027-02-30'],
            ['ends_on', '2027-01-01'], ['add_drop_ends_on', '2026-12-01'],
            ['add_drop_ends_on', '2027-05-15']] as [$field, $value]) {
            $response = $this->submit($fixture, array_replace($this->valid(), [$field => $value]));
            self::assertSame(422, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($field, $body['errors'][0]['field']);
            self::assertNotSame('Invalid request.', $body['error']);
            self::assertCount(1, $fixture->sql->rows('sis_terms'));
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testFallTermCodeWithSlashIsPreservedInStorageAndDirectory(): void
    {
        $fixture = new SisFixture();
        $response = $this->submit($fixture, ['code' => '26/FA', 'starts_on' => '2026-08-24',
            'ends_on' => '2026-12-18', 'add_drop_ends_on' => '2026-09-04']);
        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('26/FA', $fixture->sql->one('sis_terms', ['id' => $body['id']])['code']);
        $directory = new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository($fixture->sql);
        $matches = $directory->records('terms', null, '26/FA', null);
        self::assertCount(1, $matches['items']);
        self::assertSame('26/FA', $matches['items'][0]['code']);
    }

    public function testMissingFieldsReturnCorrectableErrors(): void
    {
        $fixture = new SisFixture();
        $response = $this->submit($fixture, ['code' => '2027SP']);
        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['starts_on', 'ends_on', 'add_drop_ends_on'], array_column($body['errors'], 'field'));
        self::assertCount(1, $fixture->sql->rows('sis_terms'));
    }

    public function testDeadlineCanBeFirstOrLastDay(): void
    {
        $fixture = new SisFixture();
        foreach (['2027-01-11', '2027-05-14'] as $index => $deadline) {
            $response = $this->submit($fixture, array_replace($this->valid(),
                ['code' => 'EXAMPLE-' . $index, 'add_drop_ends_on' => $deadline]));
            self::assertSame(201, $response->getStatusCode());
        }
    }

    public function testTermCreationStillRequiresCatalogPermission(): void
    {
        $fixture = new SisFixture();
        $fixture->access->staff = false;
        self::assertSame(403, $this->submit($fixture, $this->valid())->getStatusCode());
        self::assertCount(1, $fixture->sql->rows('sis_terms'));
    }
}
