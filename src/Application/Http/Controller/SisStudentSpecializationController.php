<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentSpecializationService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisStudentSpecializationController
{
    public function __construct(private StudentSpecializationService $service)
    {
    }
    public function view(string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($student), new EntityId($assignment)));
    }
    public function review(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['specialization_ids'] ?? null)) {
            throw new \InvalidArgumentException('Choose a specialization list.');
        }
        $ids = $body['specialization_ids'];
        if (!array_is_list($ids) || count($ids) > 100) {
            throw new \InvalidArgumentException('Choose a bounded specialization list.');
        }
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Use specialization identities.');
            }
        }
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($student),
            new EntityId($assignment),
            $f->text('revision', 64),
            $ids,
            $f->bodyText('reason'),
        ));
    }
    public function commit(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->commit(
            new EntityId($student),
            new EntityId($assignment),
            FormInput::from($request)->text('review_token', 32768),
        ));
    }
    public function history(ServerRequest $request, string $student, string $assignment): ResponseInterface
    {
        return new JsonResponse($this->service->history(
            new EntityId($student),
            new EntityId($assignment),
            FormInput::from($request)->optionalText('cursor', 16384),
        ));
    }
}
