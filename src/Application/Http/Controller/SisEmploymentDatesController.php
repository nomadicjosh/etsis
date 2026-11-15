<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\HumanResources\Service\EmploymentDatesService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisEmploymentDatesController
{
    public function __construct(private EmploymentDatesService $service)
    {
    }
    public function get(string $employee): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($employee)));
    }
    public function review(ServerRequest $request, string $employee): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($employee),
            $f->id('period_id'),
            $f->integer('version'),
            $f->text('from', 10),
            $f->optionalText('through', 10),
            $f->requiredBoolean('voided'),
            $f->bodyText('reason')
        ));
    }
    public function confirm(ServerRequest $request, string $employee): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->confirm(
            new EntityId($employee),
            $f->id('period_id'),
            $f->integer('version'),
            $f->text('from', 10),
            $f->optionalText('through', 10),
            $f->requiredBoolean('voided'),
            $f->bodyText('reason'),
            $f->text('position_revision', 64)
        );
        return new JsonResponse(['status' => 'saved']);
    }
}
