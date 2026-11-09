<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisBookingController
{
    public function __construct(private BookingService $service)
    {
    }

    public function room(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $id = $this->service->addRoom($f->text('code', 80), $f->integer('capacity', 1, 100000));

        return new JsonResponse(['id' => $id->value], 201);
    }

    public function request(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $slots = array_map(
            static fn (FormInput $s): TimeSlot => new TimeSlot($s->instant('starts_at'), $s->instant('ends_at')),
            $f->items('slots', 366)
        );
        $booking = $this->service->request($f->id('room_id'), $f->text('title'), $slots);

        return new JsonResponse(['id' => $booking->id->value, 'status' => $booking->status], 201);
    }

    public function approve(ServerRequest $request, string $id): ResponseInterface
    {
        $booking = $this->service->approve(new EntityId($id), FormInput::from($request)->integer('version'));

        return new JsonResponse(['id' => $booking->id->value, 'status' => $booking->status]);
    }

    public function cancel(ServerRequest $request, string $id): ResponseInterface
    {
        $this->service->cancel(new EntityId($id), FormInput::from($request)->integer('version'));

        return new JsonResponse(['status' => 'cancelled']);
    }

    public function show(string $id): ResponseInterface
    {
        $booking = $this->service->get(new EntityId($id));

        return new JsonResponse(['id' => $booking->id->value, 'room_id' => $booking->roomId->value,
            'title' => $booking->title, 'status' => $booking->status, 'version' => $booking->version,
            'slots' => array_map(static fn (TimeSlot $s): array => ['starts_at' => $s->startsAt->format(DATE_ATOM),
                'ends_at' => $s->endsAt->format(DATE_ATOM)], $booking->slots)]);
    }
}
