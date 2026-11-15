<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\HumanResources\Service\PositionService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPositionController
{
    public function __construct(private PositionService $service)
    {
    }
    public function page(ServerRequest $request, string $employee): ResponseInterface
    {
        return new JsonResponse($this->service->page(
            new EntityId($employee),
            FormInput::from($request)->optionalId('after')
        ));
    }
    public function get(string $employee, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($employee), new EntityId($id)));
    }
    public function assign(ServerRequest $request, string $employee): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->assign(
            employee: new EntityId($employee),
            id: $f->id('id'),
            job: $f->id('job_id'),
            jobVersion: $f->integer('job_version'),
            gradeVersion: $f->optionalInteger('grade_version'),
            from: $f->text('valid_from', 10),
            through: $f->optionalText('valid_through', 10),
            rateMinor: $f->optionalInteger('rate_minor'),
            weeklyMinutes: $f->optionalInteger('weekly_minutes'),
            reason: $f->bodyText('reason'),
            replaces: $f->optionalId('replaces_id'),
            replacedVersion: $f->optionalInteger('replaced_version')
        );
        return new JsonResponse(['id' => $id->value], 201);
    }
    public function end(ServerRequest $request, string $employee, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->end(
            new EntityId($employee),
            new EntityId($id),
            $f->integer('version'),
            $f->optionalText('valid_through', 10),
            $f->bodyText('reason')
        );
        return new JsonResponse(['status' => 'saved']);
    }
    public function void(ServerRequest $request, string $employee, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->setVoid(
            new EntityId($employee),
            new EntityId($id),
            $f->integer('version'),
            $f->requiredBoolean('voided'),
            $f->bodyText('reason')
        );
        return new JsonResponse(['status' => 'saved']);
    }
}
