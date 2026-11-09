<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\CourseGroupService;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCourseGroupController
{
    public function __construct(private CourseGroupService $service)
    {
    }
    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->page(
                $f->optionalText("search", 80) ?? "",
                $f->optionalId("after"),
                $f->requiredBoolean("published_only"),
            ),
        );
    }
    public function create(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(
            $this->service->create(FormInput::from($request)->id("id"), $this->definition($request)),
            201,
        );
    }
    public function view(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->save(
                new EntityId($id),
                FormInput::from($request)->integer("revision", 0),
                $this->definition($request),
            ),
        );
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->review(new EntityId($id), FormInput::from($request)->bodyText("reason")),
        );
    }
    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->publish(
                new EntityId($id),
                FormInput::from($request)->text("review_token", 131072),
            ),
        );
    }
    public function versions(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse(
            $this->service->versions(
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
    public function version(string $id, string $version): ResponseInterface
    {
        return new JsonResponse($this->service->version(new EntityId($id), new EntityId($version)));
    }
    private function definition(ServerRequest $request): CourseGroupDefinition|CourseGroupDefinitionV2
    {
        $body = $request->getParsedBody();
        $d = is_array($body) ? $body["definition"] ?? null : null;
        if (!is_array($d) || array_is_list($d)) {
            throw new \InvalidArgumentException("Provide a course group definition.");
        }
        return CourseGroupDefinitionV2::draft($d);
    }
}
