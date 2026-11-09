<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentRecordService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentRecordController
{
    public function __construct(private StudentRecordService $service)
    {
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }

    public function activity(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->activity(new EntityId($id), $f->requiredBoolean('active'), $f->text('revision', 64));

        return new JsonResponse(['status' => 'saved']);
    }

    public function programs(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->programs(
            new EntityId($id),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function assign(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $assigned = $this->service->assign(new EntityId($id), $f->id('program_id'), $f->id('entry_term_id'));

        return new JsonResponse(['id' => $assigned->value], 201);
    }

    public function primary(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->primary(new EntityId($id), $f->id('assignment_id'), $f->text('revision', 64));

        return new JsonResponse(['status' => 'saved']);
    }

    public function programActivity(ServerRequest $request, string $id, string $assignment): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->programActivity(
            new EntityId($id),
            new EntityId($assignment),
            $f->requiredBoolean('active'),
            $f->text('revision', 64)
        );

        return new JsonResponse(['status' => 'saved']);
    }
}
