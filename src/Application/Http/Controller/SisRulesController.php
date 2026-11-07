<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Rules\Service\RuleService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisRulesController
{
    public function __construct(private RuleService $service)
    {
    }

    public function save(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(['id' => $this->service->save(
            FormInput::from($request)->text('name'),
            $this->rules($request)
        )->value], 201);
    }

    public function get(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->get(new EntityId($id)));
    }

    public function preview(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse(['items' => $this->service->preview($this->rules($request)), 'limit' => 500]);
    }

    /** @return array<string, mixed> */
    private function rules(ServerRequest $request): array
    {
        $data = $request->getParsedBody();
        if (!is_array($data) || !is_array($data['rules'] ?? null)) {
            throw new InvalidArgumentException('A rule group is required.');
        }

        return $data['rules'];
    }
}
