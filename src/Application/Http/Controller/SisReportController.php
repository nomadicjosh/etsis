<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Reporting\Service\ReportService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisReportController
{
    public function __construct(private ReportService $service)
    {
    }

    public function create(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $report = $this->service->create(
            $f->text('name'),
            $f->optionalId('program_id'),
            $f->optionalId('term_id'),
            $f->optionalBoolean('active')
        );

        return new JsonResponse(['id' => $report->id->value], 201);
    }

    public function share(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->share(new EntityId($id), FormInput::from($request)->id('user_id'));

        return new JsonResponse(['status' => 'shared']);
    }

    public function shares(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->shares(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function unshare(string $id, string $user): ResponseInterface
    {
        $this->service->unshare(new EntityId($id), new EntityId($user));

        return new JsonResponse(['status' => 'revoked']);
    }

    public function run(string $id): ResponseInterface
    {
        return new JsonResponse(['rows' => $this->service->run(new EntityId($id)), 'limit' => 500]);
    }
}
