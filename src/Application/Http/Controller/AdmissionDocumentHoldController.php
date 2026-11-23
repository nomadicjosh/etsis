<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionDocumentHoldService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionDocumentHoldController
{
    public function __construct(private AdmissionDocumentHoldService $service)
    {
    }

    public function history(#[\SensitiveParameter] ServerRequest $request, string $application): ResponseInterface
    {
        $before = new FormInput($request->getQueryParams())->optionalId('before');
        return new JsonResponse($this->service->listing(
            new EntityId($application),
            $before,
        ));
    }

    public function review(#[\SensitiveParameter] ServerRequest $request, string $application): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($application),
            $f->text('scope', 6),
            $f->text('action', 7),
            $f->text('revision', 64),
            $f->text('reason', 1000),
        ));
    }

    public function confirm(#[\SensitiveParameter] ServerRequest $request, string $application): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->confirm(
            new EntityId($application),
            $f->id('operation_id'),
            $f->text('scope', 6),
            $f->text('action', 7),
            $f->text('revision', 64),
            $f->text('reason', 1000),
            $f->text('review_token', 8192),
        ));
    }
}
