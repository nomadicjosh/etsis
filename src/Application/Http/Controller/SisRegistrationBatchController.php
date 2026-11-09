<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Registration\Service\RegistrationBatchService;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRegistrationBatchController
{
    public function __construct(private RegistrationBatchService $service)
    {
    }

    public function review(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->service->review($this->selection($request)));
    }

    public function commit(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->commit(
            $f->id('operation_id'),
            $this->selection($request),
            $f->text('revision', 64)
        ));
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->receipt(new EntityId($id)));
    }

    private function selection(ServerRequest $request): RegistrationBatchSelection
    {
        $body = $request->getParsedBody();
        $students = is_array($body) ? ($body['student_ids'] ?? null) : null;
        if (!is_array($students) || !array_is_list($students) || count($students) > RegistrationBatchSelection::LIMIT) {
            throw new ValidationException([['field' => 'students', 'message' => 'Select 1–25 distinct students.']]);
        }
        $ids = [];
        foreach ($students as $id) {
            if (!is_string($id)) {
                throw new ValidationException([['field' => 'students', 'message' => 'Select valid student records.']]);
            }
            $ids[] = new EntityId($id);
        }
        return new RegistrationBatchSelection(FormInput::from($request)->id('section_id'), ...$ids);
    }
}
