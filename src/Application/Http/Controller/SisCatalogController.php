<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Codefy\Framework\Http\BaseController;
use Domain\Catalog\Service\CatalogService;
use Domain\Shared\Exception\ValidationException;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final class SisCatalogController extends BaseController
{
    public function __construct(private readonly CatalogService $service)
    {
    }

    public function program(ServerRequest $request): ResponseInterface
    {
        $data = $request->getParsedBody();
        $program = $this->service->createProgram($this->text($data, "code"), $this->text($data, "title"));

        return new JsonResponse(["id" => $program->id->value, "code" => $program->code], 201);
    }

    private function text(mixed $data, string $key): string
    {
        if (!is_array($data) || !isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === "") {
            throw new InvalidArgumentException("A required field is missing or invalid.");
        }

        return trim($data[$key]);
    }
}
