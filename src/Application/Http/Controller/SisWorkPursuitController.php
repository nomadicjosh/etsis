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
            FormInput::from($request)->optionalId('after'),
            FormInput::from($request)->optionalId('assignment_id')
        ));
    }

    public function pursuits(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->pursuits(
            new EntityId($student),
            FormInput::from($request)->optionalId('after')
        ));
    }

    public function history(ServerRequest $request, string $student, string $credit): ResponseInterface
    {
        return new JsonResponse($this->service->history(
            new EntityId($student),
            new EntityId($credit),
            FormInput::from($request)->optionalText('cursor', 4096)
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
    public function reviewReuse(ServerRequest $request, string $student): ResponseInterface
    {
        $form = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['credit_ids'] ?? null)) {
            throw new \Domain\Shared\Exception\ValidationException([
                ['field' => 'credit_ids', 'message' => 'Choose academic records to review for reuse.']
            ]);
        }
        return new JsonResponse($this->service->reviewReuse(
            new EntityId($student),
            $form->id('assignment_id'),
            $body['credit_ids'],
            $form->bodyText('reason')
        ));
    }

    public function commitReuse(ServerRequest $request, string $student): ResponseInterface
    {
        return new JsonResponse($this->service->commitReuse(
            new EntityId($student),
            FormInput::from($request)->text('review_token', 65536)
        ));
    }
}
