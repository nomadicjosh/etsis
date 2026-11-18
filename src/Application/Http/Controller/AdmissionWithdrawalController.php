<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionWithdrawalService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionWithdrawalController
{
    public function __construct(private AdmissionWithdrawalService $service)
    {
    }
    public function current(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(
            new EntityId($id),
            false,
            new FormInput($request->getQueryParams())->optionalId('after')
        ));
    }
    public function mine(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(
            new EntityId($id),
            true,
            new FormInput($request->getQueryParams())->optionalId('after')
        ));
    }
    public function queue(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->queue(new FormInput($request->getQueryParams())->optionalId('after')));
    }
    public function review(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return $this->preview($request, $id, false);
    }
    public function reviewMine(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return $this->preview($request, $id, true);
    }
    private function preview(#[\SensitiveParameter] ServerRequest $request, string $id, bool $mine): ResponseInterface
    {
        $f = FormInput::from($request);
        $reason = $request->getParsedBody()['reason'] ?? '';
        if (!is_string($reason)) {
            throw new \InvalidArgumentException('Enter a withdrawal reason or response.');
        }
        return new JsonResponse($this->service->review(
            new EntityId($id),
            $f->text('action', 10),
            $f->optionalId('request_id'),
            $reason,
            $mine
        ));
    }
    public function commit(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($id),
            FormInput::from($request)->text('review_token', AdmissionWithdrawalService::MAX_REVIEW_BYTES),
            false
        ));
    }
    public function commitMine(#[\SensitiveParameter] ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($id),
            FormInput::from($request)->text('review_token', AdmissionWithdrawalService::MAX_REVIEW_BYTES),
            true
        ));
    }
}
