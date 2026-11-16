<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\BookingUpdatesService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class PortalBookingUpdatesController
{
    public function __construct(private BookingUpdatesService $updates)
    {
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->updates->page(
            $f->boolean('unread_only', true),
            $f->optionalText('after_at', 27),
            $f->optionalId('after_id')
        ));
    }

    public function detail(string $id): ResponseInterface
    {
        return new JsonResponse($this->updates->detail(new EntityId($id)));
    }

    public function read(ServerRequest $request, string $id): ResponseInterface
    {
        $this->updates->acknowledge(new EntityId($id), FormInput::from($request)->integer('version'));
        return new JsonResponse(['status' => 'read']);
    }
}
