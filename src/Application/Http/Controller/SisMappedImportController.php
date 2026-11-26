<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Import\Service\MappedImportService;
use Domain\Import\Service\ImportQueueService;
use Application\Http\FormInput;
use Domain\Import\Enum\CatalogImportTarget;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisMappedImportController
{
    public function __construct(private MappedImportService $service, private ImportQueueService $queue)
    {
    }

    public function preview(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $data = $this->data($request);

        return new JsonResponse(
            $this->queue->stage($data['target'] ?? 'course', $data, $this->service->preview(
                $data["source"],
                $data["csv"],
                $data["mapping"],
                $data["delimiter"] ?? ",",
                $data["choices"] ?? [],
                CatalogImportTarget::from($data["target"] ?? "course"),
            )),
        );
    }

    public function commit(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->queue->submit(FormInput::from($request)->text('review_token', 4096)));
    }

    /** @return array<string, mixed> */
    private function data(#[\SensitiveParameter] ServerRequest $request): array
    {
        $data = $request->getParsedBody();
        if (
            !is_array($data) ||
            !is_string($data["source"] ?? null) ||
            !is_string($data["csv"] ?? null) ||
            !is_array($data["mapping"] ?? null) ||
            (array_key_exists("target", $data) &&
                (!is_string($data["target"]) || CatalogImportTarget::tryFrom($data["target"]) === null)) ||
            (isset($data["delimiter"]) && !is_string($data["delimiter"])) ||
            (isset($data["choices"]) && (!is_array($data["choices"]) || !array_is_list($data["choices"])))
        ) {
            throw new InvalidArgumentException("CSV and mapping are required.");
        }
        foreach ($data["mapping"] as $key => $value) {
            if (!is_string($key) || !is_int($value)) {
                throw new InvalidArgumentException("A field mapping is invalid.");
            }
        }

        return array_intersect_key($data, array_flip([
            "source", "csv", "mapping", "delimiter", "choices", "target",
        ]));
    }
}
