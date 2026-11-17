<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionCaseworkService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionCaseworkController
{
    public function __construct(private AdmissionCaseworkService $service)
    {
    }
    public function view(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view(new EntityId($id)));
    }
    public function applicant(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->applicant(new EntityId($id)));
    }
    public function save(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->service->save(
            new EntityId($id),
            $this->values($request),
            $f->integer('version'),
            $f->text('claim', 20),
            $f->optionalId('assignee_id')
        );
        return new JsonResponse(['status' => 'saved']);
    }
    public function queue(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        $due = $f->search('due', 1);
        if (!in_array($due, ['', '0', '1'], true)) {
            throw new InvalidArgumentException('Choose all or due follow-ups.');
        }
        return new JsonResponse($this->service->queue(
            $f->search('scope', 20) ?: 'all',
            $due === '1',
            $f->optionalId('after')
        ));
    }
    public function assignees(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->assignees($f->search('search', 80), $f->optionalId('after')));
    }
    public function presets(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->service->presets(
            $f->id('application_id'),
            $f->search('search', 80),
            $f->optionalId('after')
        ));
    }
    public function preset(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->preset(new EntityId($id)));
    }
    public function savePreset(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->savePreset(
            new EntityId($id),
            $this->values($request),
            FormInput::from($request)->integer('version')
        );
        return new JsonResponse(['status' => 'saved', 'new_id' => EntityId::generate()->value]);
    }
    /** @return array<string,mixed> */
    private function values(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['values'] ?? null)) {
            throw new InvalidArgumentException('Supply the working checklist values.');
        }
        return $body['values'];
    }
}
