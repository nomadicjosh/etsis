<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Import\Service\MappedImportService;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMappedImportController
{
    public function __construct(private MappedImportService $service)
    {
    }

    public function preview(ServerRequest $request): ResponseInterface
    {
        $data = $this->data($request);

        return new JsonResponse($this->service->preview($data['csv'], $data['mapping'], $data['delimiter'] ?? ','));
    }

    public function commit(ServerRequest $request): ResponseInterface
    {
        $data = $this->data($request);
        if (!is_string($data['source'] ?? null) || !is_string($data['fingerprint'] ?? null)) {
            throw new InvalidArgumentException('Review the import before continuing.');
        }

        return new JsonResponse(['imported' => $this->service->commit(
            $data['source'],
            $data['csv'],
            $data['mapping'],
            $data['fingerprint'],
            $data['delimiter'] ?? ','
        )]);
    }

    /** @return array<string, mixed> */
    private function data(ServerRequest $request): array
    {
        $data = $request->getParsedBody();
        if (
            !is_array($data) || !is_string($data['csv'] ?? null) || !is_array($data['mapping'] ?? null)
            || (isset($data['delimiter']) && !is_string($data['delimiter']))
        ) {
            throw new InvalidArgumentException('CSV and mapping are required.');
        }
        foreach ($data['mapping'] as $key => $value) {
            if (!is_string($key) || !is_int($value)) {
                throw new InvalidArgumentException('A field mapping is invalid.');
            }
        }

        return $data;
    }
}
