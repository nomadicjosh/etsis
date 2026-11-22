<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Gradebook\Assignment;
use Domain\AcademicRecord\ValueObject\AcademicDateKind;
use Domain\Gradebook\Service\GradebookService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisGradebookController
{
    public function __construct(
        private GradebookService $service,
        private \Domain\AcademicRecord\Service\AcademicDetailsService $dates,
        private \Domain\AcademicRecord\Service\AcademicCourseService $courses,
    ) {
    }

    public function calculationSetup(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->calculationSetup(new EntityId($id)));
    }

    public function saveCalculation(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        $weights = is_array($body) ? $body["weights"] ?? null : null;
        $policy = is_array($body) ? $body["grade_policy"] ?? null : null;
        if (!is_array($policy)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "grade_policy",
                    "message" => "Supply the reviewed final-grade comparison policy.",
                ],
            ]);
        }
        $categories = is_array($body) ? $body["categories"] ?? [] : [];
        if (!is_array($weights) || !is_array($categories)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => !is_array($categories) ? "categories" : "weights",
                    "message" => "Supply valid assignment weights and category lists.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->service->saveCalculation(
                new EntityId($id),
                $f->text("mode", 8),
                $weights,
                $f->text("revision", 64),
                $categories,
                new \Domain\Gradebook\ValueObject\CourseworkGradePolicy($policy),
            ),
        );
    }

    public function summary(string $id, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->summary(new EntityId($student), new EntityId($id)));
    }

    public function assignment(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $assignment = new Assignment(
            EntityId::generate(),
            new EntityId($id),
            $f->text("title"),
            $f->integer("possible_hundredths", 0, 1000000),
            $f->date("due_on"),
            $this->bonus($f),
        );
        $this->service->addAssignment($assignment);

        return new JsonResponse(["id" => $assignment->id->value], 201);
    }

    public function showAssignment(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->assignment(new EntityId($id)));
    }

    public function editAssignment(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->editAssignment(
            new EntityId($id),
            $f->text("title"),
            $f->integer("possible_hundredths", 0, 1000000),
            $f->date("due_on"),
            $f->text("revision", 64),
            $this->bonus($f),
        );

        return new JsonResponse(["status" => "saved"]);
    }

    public function scorePage(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->scorePage(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }

    public function scoreRoster(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->scoreRoster(
                new EntityId($id),
                $f->search(),
                $f->optionalText("state", 8) ?? "all",
                $f->optionalId("after"),
            ),
        );
    }

    public function attendanceHistory(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->attendanceHistory(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("after"),
            ),
        );
    }

    public function score(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->score(
            new EntityId($id),
            $f->id("student_id"),
            $f->optionalInteger("earned_hundredths"),
            $f->integer("version"),
            $this->excusal($f),
            $f->optionalText("assignment_revision", 64),
            $f->optionalId("enrollment_id"),
        );

        return new JsonResponse(["status" => "graded"]);
    }

    public function gradeChoices(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->gradeChoices(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalId("assignment_id"),
            ),
        );
    }
    public function reviewFinalGrade(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewFinalGrade(
                $f->id("student_id"),
                new EntityId($id),
                $f->id("grade_id"),
                $f->integer("version", 0),
                $f->optionalId("assignment_id"),
                $this->reason($request, $f),
                $f->optionalText("effective_on", 10),
                $f->boolean("grade_evidence"),
                $f->optionalText("coursework_revision", 64),
                $f->id("enrollment_id"),
                $f->boolean("initial_only"),
                $f->boolean("reconcile_withdrawal_quantities"),
                $f->optionalInteger("attempted_milli"),
            ),
        );
    }
    public function commitFinalGrade(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->commitFinalGrade(
                $f->id("student_id"),
                new EntityId($id),
                $f->text(
                    "review_token",
                    \Domain\AcademicRecord\Service\InstitutionalCreditLedger::REVIEW_TOKEN_LIMIT,
                ),
            ),
        );
    }
    public function gradeHistory(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->service->gradeHistory(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->optionalText("cursor", 16384),
            ),
        );
    }
    public function reviewChronology(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        foreach (["academic_on", "completed_on"] as $field) {
            if (!is_array($body) || !array_key_exists($field, $body)) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ["field" => $field, "message" => "Enter this date, or explicitly mark it unknown."],
                ]);
            }
        }
        return new JsonResponse(
            $this->dates->reviewChronology(
                new EntityId($student),
                new EntityId($id),
                $f->id("enrollment_id"),
                $f->optionalText("academic_on", 10),
                $f->optionalText("completed_on", 10),
                $f->integer("version", 0),
                $f->bodyText("reason"),
            ),
        );
    }

    public function commitChronology(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->dates->commitChronology(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function reviewAcademicDetails(
        ServerRequest $request,
        string $id,
        string $student,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        foreach (["academic_on", "completed_on"] as $field) {
            if (!is_array($body) || !array_key_exists($field, $body)) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ["field" => $field, "message" => "Enter the source date or explicitly mark it unknown."],
                ]);
            }
        }
        return new JsonResponse(
            $this->dates->reviewDetails(
                new EntityId($student),
                new EntityId($id),
                $f->id("enrollment_id"),
                $f->optionalText("academic_on", 10),
                $f->optionalText("completed_on", 10),
                $f->id("posting_term_id"),
                $f->integer("version"),
                $f->bodyText("reason"),
                $f->text("effective_on", 10),
            ),
        );
    }

    public function commitAcademicDetails(
        ServerRequest $request,
        string $id,
        string $student,
    ): ResponseInterface {
        return new JsonResponse(
            $this->dates->commitDetails(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function reviewCompletion(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return $this->reviewDate($request, $id, $student, AcademicDateKind::Completion);
    }
    public function reviewAcademicPeriod(
        ServerRequest $request,
        string $id,
        string $student,
    ): ResponseInterface {
        return $this->reviewDate($request, $id, $student, AcademicDateKind::Period);
    }
    private function reviewDate(
        ServerRequest $request,
        string $id,
        string $student,
        AcademicDateKind $kind,
    ): ResponseInterface {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists($kind->value, $body)) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => $kind->value,
                    "message" => "Enter the " . $kind->label() . ", or explicitly clear it.",
                ],
            ]);
        }
        return new JsonResponse(
            $this->dates->review(
                new EntityId($student),
                new EntityId($id),
                $f->id("enrollment_id"),
                $f->optionalText($kind->value, 10),
                $f->integer("version", 0),
                $f->bodyText("reason"),
                kind: $kind,
            ),
        );
    }
    public function commitCompletion(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        return new JsonResponse(
            $this->dates->commit(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function commitAcademicPeriod(
        ServerRequest $request,
        string $id,
        string $student,
    ): ResponseInterface {
        return new JsonResponse(
            $this->dates->commit(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
                kind: AcademicDateKind::Period,
            ),
        );
    }

    public function academicCourseSearch(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->courses->search(
            new EntityId($student),
            new EntityId($id),
            $f->id("enrollment_id"),
            $f->search(),
            $f->optionalId("after")
        ));
    }

    public function coursePublications(ServerRequest $request, string $id, string $student): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->courses->choices(
                new EntityId($student),
                new EntityId($id),
                $f->id("enrollment_id"),
                $f->optionalText("cursor", 4096),
                $f->optionalId("course_id"),
            ),
        );
    }
    public function reviewCoursePublication(
        ServerRequest $request,
        string $id,
        string $student,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->courses->review(
                new EntityId($student),
                new EntityId($id),
                $f->id("enrollment_id"),
                $f->id("course_version_id"),
                $f->integer("version", 0),
                $f->text("effective_on", 10),
                $f->bodyText("reason"),
                $f->optionalId("course_id"),
            ),
        );
    }
    public function commitCoursePublication(
        ServerRequest $request,
        string $id,
        string $student,
    ): ResponseInterface {
        return new JsonResponse(
            $this->courses->commit(
                new EntityId($student),
                new EntityId($id),
                FormInput::from($request)->text("review_token", 16384),
            ),
        );
    }

    public function attendanceRoster(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->attendanceRoster(
                new EntityId($id),
                $f->optionalText("held_on", 10) === null ? null : $f->date("held_on"),
                $f->search(),
                $f->optionalText("state", 12) ?? "all",
                $f->optionalId("after"),
            ),
        );
    }

    public function attendance(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->attendance(
                $f->id("student_id"),
                new EntityId($id),
                $f->date("held_on"),
                $f->text("status", 12),
                $f->optionalText("revision", 64),
                $f->optionalId("enrollment_id"),
            ),
        );
    }

    public function scores(string $id, string $student): ResponseInterface
    {
        return new JsonResponse([
            "scores" => $this->service->scores(new EntityId($student), new EntityId($id)),
        ]);
    }
    private function reason(ServerRequest $request, FormInput $f): string
    {
        $body = $request->getParsedBody();
        return !is_array($body) || !array_key_exists("reason", $body) || $body["reason"] === ""
        ? ""
        : $f->bodyText("reason");
    }
    private function bonus(FormInput $input): int
    {
        try {
            return $input->optionalInteger("bonus_hundredths") ?? 0;
        } catch (\InvalidArgumentException) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "bonus_hundredths",
                    "message" => "Enter a non-negative bonus limit in hundredths of a point.",
                ],
            ]);
        }
    }
    private function excusal(FormInput $input): ?bool
    {
        try {
            return $input->optionalBoolean("excused");
        } catch (\InvalidArgumentException) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "excused",
                    "message" => "Choose whether this assignment is excused.",
                ],
            ]);
        }
    }
}
