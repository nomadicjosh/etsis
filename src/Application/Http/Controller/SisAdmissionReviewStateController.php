<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionReviewStateService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAdmissionReviewStateController
{
    public function __construct(private AdmissionReviewStateService $service)
    {
    }
    public function review(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($id),
            $form->requiredBoolean('withdraw'),
            $form->bodyText('reason')
        ));
    }
    public function commit(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($id),
            FormInput::from($request)->text('review_token', 100000)
        ));
    }
}
