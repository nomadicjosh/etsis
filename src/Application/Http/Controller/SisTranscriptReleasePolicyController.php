<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Transcript\ValueObject\TranscriptStandingPresentation;
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

    public function standings(ServerRequest $request): ResponseInterface
    {
        $input = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->standingChoices(
            $input->optionalText('search', 80) ?? '',
            $input->optionalId('after'),
        ));
    }

    public function save(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) || !array_key_exists("checks", $body)
            || !array_key_exists('standing_definitions', $body)
            || !is_array($body['standing_presentation'] ?? null)
        ) {
            throw new \InvalidArgumentException(
                \Codefy\Framework\Helpers\trans_html(
                    'Supply release checks, an explicit standing selection and its presentation choices.'
                ),
            );
        }
        $input = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                $input->integer("revision"),
                $input->values("checks", 12),
                $input->requiredBoolean("include_standing"),
                $body['standing_definitions'] === null ? null : $input->values('standing_definitions', 25),
                TranscriptStandingPresentation::fromArray($body['standing_presentation']),
            ),
        );
    }
}
