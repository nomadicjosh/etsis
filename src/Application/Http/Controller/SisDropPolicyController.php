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
    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        string $term,
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $windows = is_array($body) ? $body["windows"] ?? null : null;
        if (!is_array($windows) || !array_is_list($windows)) {
            throw new ValidationException([
                [
                    "field" => "windows",
                    "message" => "Provide the dated grade periods.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->service->review(
                new EntityId($term),
                FormInput::from($request)->integer("version", -1),
                new DropWindows($windows),
                is_string($body["reason"] ?? null) ? $body["reason"] : "",
            ),
        );
    }
    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
        string $term,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($term),
                FormInput::from($request)->text("review_token", 65536),
            ),
        );
    }

    public function history(
        ServerRequest $request,
        string $term,
    ): ResponseInterface {
        $cursor = $request->getQueryParams()["cursor"] ?? null;
        if (
            $cursor !== null &&
            (!is_string($cursor) || strlen($cursor) > 8192)
        ) {
            throw new \InvalidArgumentException(
                "A valid history cursor is required.",
            );
        }
        return new JsonResponse(
            $this->service->history(new EntityId($term), $cursor),
        );
    }
}
