<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Course;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Section;
use Domain\Registration\Service\RegistrarCatalogService;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRegistrationController
{
    public function __construct(private RegistrationService $service, private RegistrarCatalogService $catalog)
    {
    }

    public function course(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $course = new Course(
            EntityId::generate(),
            $f->text('code', 80),
            $f->text('title'),
            $f->integer('credits_milli', 0, 999999)
        );
        $this->catalog->addCourse($course);

        return new JsonResponse(['id' => $course->id->value], 201);
    }

    public function section(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $section = new Section(
            EntityId::generate(),
            $f->id('course_id'),
            $f->id('term_id'),
            $f->optionalId('faculty_user_id'),
            $f->text('code', 80),
            $f->integer('capacity', 1, 100000),
            true,
            $f->boolean('web_registration'),
            new Money($f->integer('fee_minor'), $f->text('currency', 3))
        );
        $this->catalog->offer($section);

        return new JsonResponse(['id' => $section->id->value], 201);
    }

    public function window(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->catalog->configureWindow(new EntityId($id), new RegistrationWindow(
            new DateRange($f->instant('opens_at'), $f->instant('closes_at')),
            $f->integer('course_limit', 1, 1000)
        ));

        return new JsonResponse(['status' => 'configured']);
    }

    public function prerequisite(ServerRequest $request, string $id): ResponseInterface
    {
        $this->catalog->requireCourse(new EntityId($id), FormInput::from($request)->id('required_course_id'));

        return new JsonResponse(['status' => 'added'], 201);
    }

    public function removePrerequisite(string $id, string $required): ResponseInterface
    {
        $this->catalog->removeRequiredCourse(new EntityId($id), new EntityId($required));

        return new JsonResponse(['status' => 'removed']);
    }

    public function restriction(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $restriction = new Restriction(
            EntityId::generate(),
            $f->id('person_id'),
            $f->text('code', 22),
            $f->integer('severity', 0, 1000),
            new DateRange($f->date('starts_on'), $f->optionalDate('ends_on')),
            $f->boolean('blocks_staff')
        );
        $this->catalog->restrict($restriction);

        return new JsonResponse(['id' => $restriction->id->value], 201);
    }

    public function register(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $enrollment = $this->service->register($f->id('student_id'), $f->id('section_id'), $f->boolean('by_staff'));

        return new JsonResponse(['id' => $enrollment->id->value, 'status' => $enrollment->status->value], 201);
    }

    public function drop(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $enrollment = $this->service->drop($f->id('student_id'), $f->id('section_id'));

        return new JsonResponse(['id' => $enrollment->id->value, 'status' => $enrollment->status->value]);
    }

    public function endRestriction(ServerRequest $request, string $id): ResponseInterface
    {
        $this->catalog->endRestriction(new EntityId($id), FormInput::from($request)->date('ends_on'));

        return new JsonResponse(['status' => 'ended']);
    }
}
