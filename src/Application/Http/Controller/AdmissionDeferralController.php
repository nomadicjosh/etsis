<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionDeferralService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionDeferralController
{
    public function __construct(private AdmissionDeferralService $service)
    {
    }
    public function current(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id)));
    }
    public function choices(ServerRequest $request, string $id): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->choices(
            new EntityId($id),
            $f->search('search', 80),
            $f->optionalId('after')
        ));
    }
    public function review(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($id),
            $f->id('target_term'),
            $f->text('kind', 10),
            $f->bodyText('reason')
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
