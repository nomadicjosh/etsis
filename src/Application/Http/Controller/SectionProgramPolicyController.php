<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\SectionProgramPolicyService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SectionProgramPolicyController
{
    public function __construct(private SectionProgramPolicyService $service)
    {
    }

    public function current(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id)));
    }

    public function choices(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->choices(
                new EntityId($id),
                $f->optionalText("search", 80) ?? "",
                $f->optionalId("after"),
            ),
        );
    }

    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->save(
                new EntityId($id),
                $f->requiredValues("program_ids", 20),
                $f->text("revision", 64),
                $f->boolean('require_program_campus'),
            ),
        );
    }
}
