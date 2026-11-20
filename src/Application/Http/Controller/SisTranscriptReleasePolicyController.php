<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Transcript\Service\TranscriptReleasePolicyService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisTranscriptReleasePolicyController
{
    public function __construct(private TranscriptReleasePolicyService $service)
    {
    }

    public function checkId(): ResponseInterface
    {
        return new JsonResponse(["id" => $this->service->checkId()->value]);
    }

    public function current(): ResponseInterface
    {
        return new JsonResponse($this->service->current());
    }

    public function save(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists("checks", $body)) {
            throw new \InvalidArgumentException(
                "Supply the release checks, including an explicit empty list to clear them.",
            );
        }
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                $input->integer("revision"),
                $input->values("checks", 12),
                $input->requiredBoolean("include_standing"),
            ),
        );
    }
}
