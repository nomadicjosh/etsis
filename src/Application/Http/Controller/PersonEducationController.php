<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\EducationBackgroundService;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use InvalidArgumentException;

final readonly class PersonEducationController
{
    public function __construct(
        private EducationBackgroundService $service,
        private PersonAccountRepository $people,
        private StudentAccess $access,
    ) {
    }
    public function mine(): ResponseInterface
    {
        $person = $this->people->findByUser($this->access->authenticatedUserId())
        ?? throw new AccessDeniedException('The account needs its person record.');
        return new JsonResponse($this->service->view($person->id));
    }
    public function saveMine(ServerRequest $request): ResponseInterface
    {
        $this->service->save(
            $this->entries($request),
            FormInput::from($request)->integer('version'),
            $this->reason($request)
        );
        return new JsonResponse(['status' => 'saved']);
    }
    public function staff(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->staffView(new EntityId($id)));
    }
    public function saveStaff(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->staffSave(
            new EntityId($id),
            $this->entries($request),
            FormInput::from($request)->integer('version'),
            $this->reason($request)
        );
        return new JsonResponse(['status' => 'saved']);
    }
    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) || !is_string($body['entry_key'] ?? null)
            || !is_string($body['review_status'] ?? null)
        ) {
            throw new InvalidArgumentException('Choose an institution and review outcome.');
        }
        $this->service->review(
            new EntityId($id),
            $body['entry_key'],
            $body['review_status'],
            FormInput::from($request)->integer('version'),
            $this->reason($request)
        );
        return new JsonResponse(['status' => 'saved']);
    }
    private function reason(ServerRequest $request): string
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['reason'] ?? '')) {
            throw new InvalidArgumentException('Supply the reason for this update.');
        }
        return $body['reason'] ?? '';
    }
    /** @return array<mixed> */
    private function entries(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['entries'] ?? null)) {
            throw new InvalidArgumentException('Supply the institutions attended list.');
        }
        return $body['entries'];
    }
}
