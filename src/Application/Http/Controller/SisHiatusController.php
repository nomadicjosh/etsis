<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\Service\HiatusService;
use Domain\Student\StudentHiatus;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisHiatusController
{
    public function __construct(private HiatusService $service)
    {
    }

    public function page(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->page(new EntityId($student), FormInput::from($request)->optionalId("after")),
        );
    }

    public function create(ServerRequest $request, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        $hiatus = new StudentHiatus(
            $f->id("operation_id"),
            new EntityId($student),
            HiatusCode::parse($f->text("code", 8)),
            new DateRange($f->date("starts_on"), $f->optionalDate("ends_on")),
        );
        $this->service->create($hiatus, $f->bodyText("note"));
        return new JsonResponse(["id" => $hiatus->id->value], 201);
    }

    public function show(string $student, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($student), new EntityId($id)));
    }

    public function end(ServerRequest $request, string $student, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->end(
            new EntityId($student),
            new EntityId($id),
            $f->id("operation_id"),
            $f->date("ends_on"),
            $f->text("revision", 64),
            $f->bodyText("note"),
        );
        return new JsonResponse(["status" => "saved"]);
    }

    public function void(ServerRequest $request, string $student, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->void(
            new EntityId($student),
            new EntityId($id),
            $f->id("operation_id"),
            $f->text("revision", 64),
            $f->bodyText("note"),
        );
        return new JsonResponse(["status" => "voided"]);
    }

    public function history(ServerRequest $request, string $student, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
}
