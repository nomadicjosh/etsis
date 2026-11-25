<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionChangeRequestService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionChangeRequestController
{
    public function __construct(private AdmissionChangeRequestService $service)
    {
    }

    private function listing(ServerRequest $request, string $id, bool $mine): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->listing(
            new EntityId($id),
            $mine,
            $f->boolean('finished'),
            $f->optionalId('after')
        ));
    }

    public function mine(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->listing($request, $id, true);
    }

    public function staff(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->listing($request, $id, false);
    }

    public function request(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->request(
            new EntityId($id),
            $f->id('request_id'),
            $f->text('area', 20),
            $f->text('message', 1000),
            $f->integer('application_revision')
        ));
    }

    private function finish(ServerRequest $request, string $id, bool $mine): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->finish(
            new EntityId($id),
            $f->id('request_id'),
            $mine,
            $f->integer('version'),
            $f->integer('application_revision'),
            $f->text('message', 1000)
        ));
    }

    public function cancel(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->finish($request, $id, true);
    }

    public function close(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->finish($request, $id, false);
    }
}
