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
    public function show(string $term, ?string $section = null): ResponseInterface
    {
        return new JsonResponse($this->service->show(
            new EntityId($term),
            $section === null ? null : new EntityId($section),
        ));
    }
    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        string $term,
        ?string $section = null,
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
        $input = FormInput::from($request);
        $inherit = $section !== null && $input->requiredBoolean('uses_term_policy');
        return new JsonResponse(
            $this->service->review(
                new EntityId($term),
                $input->integer("version", -1),
                new DropWindows($windows),
                is_string($body["reason"] ?? null) ? $body["reason"] : "",
                $section === null ? null : new EntityId($section),
                $section === null || $inherit ? null : $input->text('grace_ends_on', 10),
                $inherit,
            ),
        );
    }
    public function commit(
        #[\SensitiveParameter] ServerRequest $request,
        string $term,
        ?string $section = null,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($term),
                FormInput::from($request)->text("review_token", 65536),
                $section === null ? null : new EntityId($section),
            ),
        );
    }

    public function history(
        ServerRequest $request,
        string $term,
        ?string $section = null,
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
            $this->service->history(new EntityId($term), $cursor, $section === null ? null : new EntityId($section)),
        );
    }
}
