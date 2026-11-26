<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\NotificationPreferencesService;
use Domain\Person\ValueObject\NotificationPreferences;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class NotificationPreferencesController
{
    public function __construct(private NotificationPreferencesService $service)
    {
    }

    public function view(?string $id = null): ResponseInterface
    {
        return new JsonResponse(
            $this->service->view($id === null ? $this->service->currentPerson() : new EntityId($id)),
        );
    }

    public function save(ServerRequest $request, ?string $id = null): ResponseInterface
    {
        $body = $request->getParsedBody();
        $values = is_array($body) ? $body["preferences"] ?? [] : [];
        if (!is_array($values)) {
            throw new \InvalidArgumentException(
                \Codefy\Framework\Helpers\trans_html("Choose valid notification preferences."),
            );
        }
        return new JsonResponse(
            $this->service->save(
                $id === null ? $this->service->currentPerson() : new EntityId($id),
                FormInput::from($request)->integer("revision", 0, 2147483646),
                new NotificationPreferences($values),
            ),
        );
    }
}
