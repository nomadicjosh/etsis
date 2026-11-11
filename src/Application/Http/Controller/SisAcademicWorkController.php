<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\DegreeAudit\Service\AcademicWorkService;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicCalculationService;
use Domain\DegreeAudit\Service\AcademicWorkMatchService;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisAcademicWorkController
{
    public function __construct(
        private AcademicWorkService $service,
        private AcademicWorkMatchService $matches,
        private AcademicInputService $inputs,
        private AcademicCalculationService $calculations,
    ) {
    }

    public function page(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->service->page(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function matches(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse(
            $this->matches->page(
                new EntityId($student),
                new EntityId($assignment),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }
    public function calculations(
        ServerRequest $request,
        string $student,
        string $assignment,
    ): ResponseInterface {
        $result = $this->calculations->preview(new EntityId($student), new EntityId($assignment));
        if ($result["status"] === "inconsistent") {
            return new JsonResponse(
                [
                    "error" =>
                        "Credits and GPA could not be verified. Ask a registrar to review this record.",
                    "status" => "inconsistent",
                ],
                409,
            );
        }
        return new JsonResponse($result);
    }
    public function verify(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        $result = $this->inputs->collect(new EntityId($student), new EntityId($assignment));
        if ($result->status === AcademicInputStatus::Inconsistent) {
            return new JsonResponse(
                [
                    "error" =>
                        "Academic records and their history could not be verified. " .
                        "Ask a registrar to review this record.",
                    "status" => $result->status->value,
                ],
                409,
            );
        }
        // Never serialize the internal snapshot: references include restricted early removals.
        return new JsonResponse([
            "status" => $result->status->value,
            "current_record_count" =>
                $result->status === AcademicInputStatus::Limited ? null : count($result->items),
        ]);
    }
}
