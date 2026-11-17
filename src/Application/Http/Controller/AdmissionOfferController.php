<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionOfferService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionOfferController
{
    public function __construct(private AdmissionOfferService $service)
    {
    }
    public function current(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id), false));
    }
    public function mine(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id), true));
    }
    public function review(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->review(new EntityId($id), $this->input($request), false));
    }
    public function reviewMine(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->review(new EntityId($id), $this->input($request), true));
    }
    public function commit(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($id),
            FormInput::from($request)->text('review_token', AdmissionOfferService::MAX_REVIEW_BYTES),
            false
        ));
    }
    public function commitMine(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($id),
            FormInput::from($request)->text('review_token', AdmissionOfferService::MAX_REVIEW_BYTES),
            true
        ));
    }
    public function policy(): ResponseInterface
    {
        return new JsonResponse($this->service->policy());
    }
    public function savePolicy(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->savePolicy(
            $f->requiredBoolean('require_acceptance'),
            $f->integer('revision')
        ));
    }
    private function input(#[\SensitiveParameter] ServerRequest $request): array
    {
        $input = $request->getParsedBody();
        if (!is_array($input)) {
            throw new \InvalidArgumentException('A parsed offer form is required.');
        }
        return $input;
    }
}
