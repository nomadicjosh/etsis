<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRequirementAdjustmentController
{
    public function __construct(private RequirementAdjustmentService $service)
    {
    }
    public function view(string $student, string $assignment, ?string $specialization = null): ResponseInterface
    {
        return new JsonResponse(
            $this->service->view(
                new EntityId($student),
                new EntityId($assignment),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function routing(string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->routing(new EntityId($student), new EntityId($assignment)));
    }
    public function preview(string $student, string $assignment, ?string $specialization = null): ResponseInterface
    {
        return new JsonResponse(
            $this->service->preview(
                new EntityId($student),
                new EntityId($assignment),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function replacementCourses(
        ServerRequest $request,
        string $student,
        string $assignment,
        ?string $specialization = null,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->replacementCourses(
                new EntityId($student),
                new EntityId($assignment),
                $f->search("search", 80),
                $f->optionalId("after"),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function replacementVersions(
        ServerRequest $request,
        string $student,
        string $assignment,
        string $course,
        ?string $specialization = null,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->replacementVersions(
                new EntityId($student),
                new EntityId($assignment),
                new EntityId($course),
                FormInput::from($request)->optionalText("cursor", 16384),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function placementGroups(
        ServerRequest $request,
        string $student,
        string $assignment,
        ?string $specialization = null,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->placementGroups(
                new EntityId($student),
                new EntityId($assignment),
                $f->id("course_id"),
                $f->id("course_version_id"),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function review(
        ServerRequest $request,
        string $student,
        string $assignment,
        ?string $specialization = null,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $d = is_array($body) ? $body["definition"] ?? null : null;
        if (!is_array($d) || array_is_list($d)) {
            throw new \InvalidArgumentException("Provide a student exception definition.");
        }
        return new JsonResponse(
            $this->service->reviewGrant(
                new EntityId($student),
                new EntityId($assignment),
                $d,
                $f->bodyText("reason"),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function revokeReview(
        ServerRequest $request,
        string $student,
        string $assignment,
        ?string $specialization = null,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewRevoke(
                new EntityId($student),
                new EntityId($assignment),
                $f->id("adjustment_id"),
                $f->bodyText("reason"),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function commit(
        ServerRequest $request,
        string $student,
        string $assignment,
        ?string $specialization = null,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->commit(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->text("review_token", 32768),
                $specialization === null ? null : new EntityId($specialization),
            ),
        );
    }
    public function history(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->history(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
}
