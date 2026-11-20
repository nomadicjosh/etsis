<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Course;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Registration\Service\RegistrarCatalogService;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRegistrationController
{
    public function __construct(
        private RegistrationService $service,
        private RegistrarCatalogService $catalog,
        private \Domain\Registration\Service\RegistrationBatchService $reviews,
    ) {
    }

    public function course(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $course = new Course(
            EntityId::generate(),
            $f->text("code", 80),
            $f->text("title"),
            $f->integer("credits_milli", 0, 999999),
        );
        $this->catalog->addCourse($course);

        return new JsonResponse(["id" => $course->id->value], 201);
    }

    public function section(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $section = new Section(
            EntityId::generate(),
            $f->id("course_id"),
            $f->id("term_id"),
            $f->optionalId("faculty_user_id"),
            $f->text("code", 80),
            $f->integer("capacity", 1, 100000),
            true,
            $f->boolean("web_registration"),
            new Money($f->integer("fee_minor"), $f->text("currency", 3)),
            \Domain\Registration\Enum\DeliveryMode::parse(
                $f->optionalText("delivery_mode", 24) ?? "unspecified",
            ),
            minimumEnrollment: $f->optionalInteger('minimum_enrollment') ?? 0,
        );
        $this->catalog->offer($section);

        return new JsonResponse(["id" => $section->id->value], 201);
    }

    public function prerequisite(
        ServerRequest $request,
        string $id,
    ): ResponseInterface {
        $this->catalog->requireCourse(
            new EntityId($id),
            FormInput::from($request)->id("required_course_id"),
        );

        return new JsonResponse(["status" => "added"], 201);
    }

    public function removePrerequisite(
        string $id,
        string $required,
    ): ResponseInterface {
        $this->catalog->removeRequiredCourse(
            new EntityId($id),
            new EntityId($required),
        );

        return new JsonResponse(["status" => "removed"]);
    }

    public function register(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $result = $this->reviews->commit(
            $f->id("operation_id"),
            new \Domain\Registration\ValueObject\RegistrationBatchSelection(
                $f->id("section_id"),
                $f->id("student_id"),
            ),
            $f->text("revision", 64),
            $f->text("review_token", 8192),
            $f->boolean("by_staff"),
        );
        return new JsonResponse(
            $result + [
                "id" => $result["registrations"][0]["enrollment_id"],
                "status" => $result["registrations"][0]["status"],
            ],
            201,
        );
    }

    public function reviewRegistration(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->reviews->review(
                new \Domain\Registration\ValueObject\RegistrationBatchSelection(
                    $f->id("section_id"),
                    $f->id("student_id"),
                ),
                $f->boolean("by_staff"),
            ),
        );
    }

    public function reviewSchedule(
        #[\SensitiveParameter] ServerRequest $request,
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->reviewSchedule(
                $f->id("student_id"),
                $f->id("section_id"),
            ),
        );
    }

    public function reviewDrop(
        ServerRequest $request,
        string $student,
        string $section,
    ): ResponseInterface {
        return new JsonResponse(
            $this->service->reviewDrop(
                new EntityId($student),
                new EntityId($section),
            ),
        );
    }
    public function dropReceipt(string $operation): ResponseInterface
    {
        return new JsonResponse(
            $this->service->dropReceipt(new EntityId($operation)),
        );
    }
    public function drop(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(
            $this->service->dropReviewed(
                $f->id("student_id"),
                $f->id("section_id"),
                $f->id("enrollment_id"),
                $f->integer("version"),
                $f->id("operation_id"),
                $f->text("revision", 64),
                $f->text("effective_on", 10),
            ),
        );
    }
}
