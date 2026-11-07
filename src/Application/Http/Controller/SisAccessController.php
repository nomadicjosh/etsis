<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Codefy\Framework\Http\BaseController;
use Application\Http\FormInput;
use Domain\Access\AccessDefinition;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Service\AccessService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final class SisAccessController extends BaseController
{
    public function __construct(private readonly AccessService $service)
    {
    }

    public function directory(ServerRequest $request, string $kind): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['search'] ?? '') || !is_string($body['grant'] ?? '')) {
            throw new InvalidArgumentException('Invalid access directory filter.');
        }
        $definition = match ($kind) {
            'roles' => DefinitionKind::Role, 'permissions' => DefinitionKind::Permission,
            default => throw new InvalidArgumentException('Unknown access definition kind.'),
        };
        $grant = ($body['grant'] ?? '') === '' ? null : (GrantKind::tryFrom($body['grant'])
            ?? throw new InvalidArgumentException('Unknown grant kind.'));

        return new JsonResponse($this->service->directory(
            $definition,
            $body['search'] ?? '',
            $f->optionalId('after'),
            $f->optionalInteger('limit') ?? 50,
            $grant,
            $f->optionalId('subject'),
            $f->boolean('assigned_only')
        ));
    }

    public function roles(ServerRequest $request): ResponseInterface
    {
        return $this->definitions($request, DefinitionKind::Role);
    }

    public function permissions(ServerRequest $request): ResponseInterface
    {
        return $this->definitions($request, DefinitionKind::Permission);
    }

    public function role(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->definition($request, DefinitionKind::Role, new EntityId($id));
    }

    public function permission(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->definition($request, DefinitionKind::Permission, new EntityId($id));
    }

    public function rolePermissions(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->assignments($request, GrantKind::RolePermission, new EntityId($id));
    }

    public function userRoles(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->assignments($request, GrantKind::UserRole, new EntityId($id));
    }

    public function userPermissions(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->assignments($request, GrantKind::UserPermission, new EntityId($id));
    }

    public function rolePermission(ServerRequest $request, string $id, string $target): ResponseInterface
    {
        return $this->assign($request, GrantKind::RolePermission, $id, $target);
    }

    public function userRole(ServerRequest $request, string $id, string $target): ResponseInterface
    {
        return $this->assign($request, GrantKind::UserRole, $id, $target);
    }

    public function userPermission(ServerRequest $request, string $id, string $target): ResponseInterface
    {
        return $this->assign($request, GrantKind::UserPermission, $id, $target);
    }

    private function definitions(ServerRequest $request, DefinitionKind $kind): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            $definition = $this->service->create($kind, $this->text($data, 'name'), $this->description($data));

            return new JsonResponse($this->serialize($definition), 201);
        }
        [$after, $limit] = $this->page($request);
        $items = array_map($this->serialize(...), $this->service->definitions($kind, $after, $limit));

        return new JsonResponse(['items' => $items,
            'next_after' => $items !== [] && count($items) === $limit ? $items[array_key_last($items)]['id'] : null]);
    }

    private function definition(ServerRequest $request, DefinitionKind $kind, EntityId $id): ResponseInterface
    {
        if ($request->getMethod() === 'DELETE') {
            $this->service->delete($kind, $id);
        } else {
            $this->service->updateDescription($kind, $id, $this->description($request->getParsedBody()));
        }

        return new EmptyResponse();
    }

    private function assignments(ServerRequest $request, GrantKind $kind, EntityId $id): ResponseInterface
    {
        [$after, $limit] = $this->page($request);
        $items = $this->service->assignments($kind, $id, $after, $limit);

        return new JsonResponse(['target_ids' => $items,
            'next_after' => $items !== [] && count($items) === $limit ? $items[array_key_last($items)] : null]);
    }

    private function assign(ServerRequest $request, GrantKind $kind, string $id, string $target): ResponseInterface
    {
        $this->service->assign($kind, new EntityId($id), new EntityId($target), $request->getMethod() === 'PUT');

        return new EmptyResponse();
    }

    /** @return array{?EntityId, int} */
    private function page(ServerRequest $request): array
    {
        $query = $request->getQueryParams();
        $after = $query['after'] ?? null;
        $limit = $query['limit'] ?? '100';
        if (
            ($after !== null && !is_string($after)) || !is_scalar($limit)
            || preg_match('/^[0-9]{1,3}$/D', (string) $limit) !== 1
        ) {
            throw new InvalidArgumentException('Invalid access list cursor or limit.');
        }

        return [$after === null ? null : new EntityId($after), (int) $limit];
    }

    private function text(mixed $data, string $key): string
    {
        if (!is_array($data) || !isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidArgumentException('A required access field is missing or invalid.');
        }

        return $data[$key];
    }

    private function description(mixed $data): string
    {
        if (!is_array($data) || !is_string($data['description'] ?? '')) {
            throw new InvalidArgumentException('Invalid access description.');
        }

        return $data['description'] ?? '';
    }

    /** @return array{id: string, name: string, description: string, is_system: bool} */
    private function serialize(AccessDefinition $definition): array
    {
        return ['id' => $definition->id->value, 'name' => $definition->name,
            'description' => $definition->description, 'is_system' => $definition->isSystem];
    }
}
