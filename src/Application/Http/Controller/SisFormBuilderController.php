<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\ApplicationForm;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisFormBuilderController
{
    public function __construct(private ApplicationFormService $service)
    {
    }

    public function listing(): ResponseInterface
    {
        return new JsonResponse(['items' => $this->service->listing()]);
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->serialize($this->service->get(new EntityId($id))));
    }

    public function active(string $id): ResponseInterface
    {
        return new JsonResponse($this->serialize($this->service->active(new EntityId($id)))
            + ['submission_id' => EntityId::generate()->value]);
    }

    public function save(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['fields'] ?? null) || !array_is_list($body['fields'])) {
            throw new InvalidArgumentException('Form fields are required.');
        }
        $fields = [];
        foreach ($body['fields'] as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Each field must be an object.');
            }
            $field = [];
            foreach ($raw as $key => $value) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException('Invalid field configuration.');
                }
                $field[$key] = $value;
            }
            $fields[] = $field;
        }
        $form = $this->service->save(
            $f->optionalId('id'),
            $f->id('program_id'),
            $f->text('name'),
            $fields,
            $f->optionalInteger('version')
        );

        return new JsonResponse($this->serialize($form), 201);
    }

    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->publish(new EntityId($id), FormInput::from($request)->integer('version'));

        return new JsonResponse(['status' => 'published']);
    }

    public function disable(string $id): ResponseInterface
    {
        $this->service->disable(new EntityId($id));

        return new JsonResponse(['status' => 'disabled']);
    }

    public function submit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['answers'] ?? null)) {
            throw new InvalidArgumentException('Application answers are required.');
        }
        $application = $this->service->submit(
            $f->id('submission_id'),
            new EntityId($id),
            $f->id('person_id'),
            $f->id('entry_term_id'),
            $body['answers']
        );

        return new JsonResponse(['id' => $application->value, 'status' => 'submitted'], 201);
    }

    public function reviewApplication(string $id): ResponseInterface
    {
        $review = $this->service->reviewApplication(new EntityId($id));
        if ($review['submission'] !== null) {
            $review['submission']['form'] = $this->serialize($review['submission']['form']);
        }

        return new JsonResponse($review);
    }

    public function submission(string $id): ResponseInterface
    {
        $record = $this->service->submission(new EntityId($id));
        $record['form'] = $this->serialize($record['form']);

        return new JsonResponse($record);
    }

    /** @return array<string, mixed> */
    private function serialize(ApplicationForm $form): array
    {
        return ['id' => $form->id->value, 'program_id' => $form->programId->value, 'name' => $form->name,
            'fields' => $form->fields, 'status' => $form->status, 'version' => $form->version];
    }
}
