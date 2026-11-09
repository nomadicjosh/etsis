<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferService;
use Domain\Transfer\TransferEquivalency;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisTransferController
{
    public function __construct(private TransferService $service)
    {
    }

    public function institution(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->addInstitution($f->text('code', 80), $f->text('name'));

        return new JsonResponse(['id' => $id->value], 201);
    }

    public function course(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->addExternalCourse($f->id('institution_id'), $f->text('code', 80), $f->text('title'));

        return new JsonResponse(['id' => $id->value], 201);
    }

    public function equate(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $equivalency = new TransferEquivalency(
            EntityId::generate(),
            $f->id('institution_id'),
            $f->id('external_course_id'),
            $f->id('course_id'),
            new DateRange($f->date('starts_on'), $f->optionalDate('ends_on')),
            $f->integer('minimum_grade_rank', 0, 10000),
            $f->integer('credits_milli', 1, 999999),
            $f->text('awarded_grade', 12)
        );
        $this->service->equate($equivalency);

        return new JsonResponse(['id' => $equivalency->id->value], 201);
    }

    public function award(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->award(
            $f->id('student_id'),
            $f->id('equivalency_id'),
            $f->id('institution_id'),
            $f->id('term_id'),
            $f->date('completed_on'),
            $f->integer('grade_rank', 0, 10000),
            $f->text('revision', 64)
        );

        return new JsonResponse(['credit_id' => $id->value], 201);
    }

    public function transcript(string $id): ResponseInterface
    {
        return new JsonResponse(['credits' => $this->service->transcript(new EntityId($id))]);
    }
}
