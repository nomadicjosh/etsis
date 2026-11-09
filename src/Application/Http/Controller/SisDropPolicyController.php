<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\DropPolicyService;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisDropPolicyController
{
    public function __construct(private DropPolicyService $service)
    {
    }
    public function show(string $term): ResponseInterface
    {
        return new JsonResponse($this->service->show(new EntityId($term)));
    }
    public function save(ServerRequest $request, string $term): ResponseInterface
    {
        $body = $request->getParsedBody();
        $windows = is_array($body) ? $body["windows"] ?? null : null;
        if (!is_array($windows) || !array_is_list($windows)) {
            throw new ValidationException([["field" => "windows", "message" => "Provide the dated grade periods."]]);
        }
        $this->service->save(
            new EntityId($term),
            FormInput::from($request)->integer("version", -1),
            new DropWindows($windows),
        );
        return new JsonResponse(["status" => "saved"]);
    }
}
