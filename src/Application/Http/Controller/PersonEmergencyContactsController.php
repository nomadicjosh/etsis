<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\EmergencyContactsService;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use InvalidArgumentException;

final readonly class PersonEmergencyContactsController
{
    public function __construct(
        private EmergencyContactsService $service,
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
        $this->service->save($this->contacts($request), FormInput::from($request)->integer('version'));
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
            $this->contacts($request),
            FormInput::from($request)->integer('version')
        );
        return new JsonResponse(['status' => 'saved']);
    }
    /** @return array<mixed> */
    private function contacts(ServerRequest $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['contacts'] ?? null)) {
            throw new InvalidArgumentException('Supply the emergency contact list.');
        }
        return $body['contacts'];
    }
}
