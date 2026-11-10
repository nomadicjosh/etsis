<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\SubjectService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisSubjectController
{
    public function __construct(private SubjectService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->page($f->optionalText("search", 100) ?? "", $f->optionalId("after")),
        );
    }
    public function save(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                $f->optionalId("id"),
                $f->text("code", 40),
                $f->text("title", 191),
                $f->requiredBoolean("active"),
                $f->optionalText("revision", 64),
            ),
        );
    }
    public function showCourse(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->course(new EntityId($id)));
    }
    public function assign(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->assign(new EntityId($id), $f->optionalId("subject_id"), $f->text("revision", 64));
        return new JsonResponse(["status" => "saved"]);
    }
}
