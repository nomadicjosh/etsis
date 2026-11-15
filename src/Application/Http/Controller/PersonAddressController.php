<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\PersonAddressService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class PersonAddressController
{
    public function __construct(private PersonAddressService $addresses)
    {
    }
    public function mine(): ResponseInterface
    {
        return new JsonResponse($this->addresses->view());
    }
    public function staff(string $id): ResponseInterface
    {
        return new JsonResponse($this->addresses->view(new EntityId($id), true));
    }
    public function saveMine(ServerRequest $request): ResponseInterface
    {
        return $this->save($request);
    }
    public function saveStaff(ServerRequest $request, string $id): ResponseInterface
    {
        return $this->save($request, new EntityId($id));
    }
    private function save(ServerRequest $request, ?EntityId $person = null): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['addresses'] ?? null)) {
            throw new \InvalidArgumentException('Supply the address list.');
        }
        $this->addresses->save($body['addresses'], FormInput::from($request)->integer('version'), $person);
        return new JsonResponse(['status' => 'saved']);
    }
}
