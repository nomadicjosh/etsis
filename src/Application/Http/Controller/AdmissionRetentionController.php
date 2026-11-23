<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionRetentionService;
use Domain\Admissions\ValueObject\AdmissionRetentionDefinition;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionRetentionController
{
    public function __construct(private AdmissionRetentionService $service)
    {
    }

    public function configuration(): ResponseInterface
    {
        return new JsonResponse($this->service->configuration());
    }

    public function policies(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $before = isset($query['before']) ? new FormInput($query)->integer('before') : null;
        return new JsonResponse($this->service->policies($before));
    }

    private function definition(#[\SensitiveParameter] ServerRequest $request): AdmissionRetentionDefinition
    {
        $body = $request->getParsedBody();
        $definition = is_array($body) ? ($body['definition'] ?? null) : null;
        return AdmissionRetentionDefinition::fromArray(is_array($definition) ? $definition : []);
    }

    public function reviewPolicy(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->reviewPolicy(
            $this->definition($request),
            $f->integer('revision'),
            $f->text('reason', 1000),
        ));
    }

    public function publishPolicy(#[\SensitiveParameter] ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->publishPolicy(
            $f->id('operation_id'),
            $this->definition($request),
            $f->integer('revision'),
            $f->text('reason', 1000),
            $f->text('review_token', 8192),
        ));
    }

    public function reviewClassification(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->reviewClassification(
            new EntityId($application),
            new EntityId($id),
            $f->integer('version'),
            $f->id('category_id'),
            $f->text('reason', 1000),
        ));
    }

    public function classify(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->classify(
            new EntityId($application),
            new EntityId($id),
            $f->id('operation_id'),
            $f->integer('version'),
            $f->id('category_id'),
            $f->text('reason', 1000),
            $f->text('revision', 64),
            $f->text('review_token', 8192),
        ));
    }

    public function history(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id,
    ): ResponseInterface {
        return new JsonResponse($this->service->history(
            new EntityId($application),
            new EntityId($id),
            new FormInput($request->getQueryParams())->optionalId('before'),
        ));
    }
}
