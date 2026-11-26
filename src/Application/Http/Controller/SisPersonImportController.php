<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Import\Service\PersonImportService;
use Domain\Import\Service\ImportQueueService;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class SisPersonImportController
{
    public function __construct(
        private PersonImportService $service,
        private ImportQueueService $queue,
    ) {
    }

    public function preview(
        #[\SensitiveParameter] ServerRequest $request,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $mapping = is_array($body) ? $body["mapping"] ?? [] : [];
        if (!is_array($mapping)) {
            throw new InvalidArgumentException(
                trans("Choose the person field mapping."),
            );
        }
        foreach ($mapping as $field => $index) {
            if (!is_string($field) || !is_int($index)) {
                throw new InvalidArgumentException(
                    trans(
                        "Choose valid CSV columns for all three person fields.",
                    ),
                );
            }
        }
        $delimiter = $body["delimiter"] ?? ",";
        $reason = $body["reason"] ?? "";
        if (
            !is_string($delimiter) ||
            !is_string($reason) ||
            mb_strlen($reason) > 500
        ) {
            throw new InvalidArgumentException(
                trans("Provide an import reason up to 500 characters."),
            );
        }
        $csv = $body["csv"] ?? null;
        if (!is_string($csv)) {
            throw new InvalidArgumentException(
                trans("Choose a UTF-8 people CSV up to 2 MB."),
            );
        }
        return new JsonResponse(
            $this->queue->stage(
                "people",
                ["csv" => $csv, "mapping" => $mapping, "delimiter" => $delimiter, "reason" => $reason],
                $this->service->analyze($csv, $mapping, $delimiter, $reason),
            ),
        );
    }

    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
    ): ResponseInterface {
        return new JsonResponse(
            $this->queue->submit(
                FormInput::from($request)->text("review_token", 4096),
            ),
        );
    }
}
