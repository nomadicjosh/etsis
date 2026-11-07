<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Import\Service\CatalogImportService;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisImportController
{
    public function __construct(private CatalogImportService $service)
    {
    }

    public function courses(ServerRequest $request, string $source): ResponseInterface
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'text/csv') {
            throw new InvalidArgumentException('A text/csv request body is required.');
        }
        $csv = $request->getBody()->read(1_048_577);

        return new JsonResponse(['imported' => $this->service->courses($source, $csv)]);
    }
}
