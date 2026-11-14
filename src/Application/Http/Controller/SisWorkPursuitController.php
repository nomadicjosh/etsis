<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisWorkPursuitController
{
    public function __construct(private WorkPursuitService $service)
    {
    }

    public function page(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->page(
            new EntityId($student),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function pursuits(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->pursuits(
            new EntityId($student),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function review(ServerRequest $request, string $student, string $credit): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($student),
            new EntityId($credit),
            $form->id('assignment_id'),
            $form->text('action', 32),
            $form->bodyText('reason')
        ));
    }

    public function commit(ServerRequest $request, string $student, string $credit): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($student),
            new EntityId($credit),
            FormInput::from($request)->text('review_token', 16384)
        ));
    }
}
