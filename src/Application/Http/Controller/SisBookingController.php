<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\TimeSlot;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\BookingPattern;
use InvalidArgumentException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisBookingController
{
    public function __construct(private BookingService $service)
    {
    }

    public function planning(): ResponseInterface
    {
        return new JsonResponse($this->service->planningContext());
    }
    public function review(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review($f->id('room_id'), $f->text('title'), $this->pattern($request)));
    }
    public function requestSeries(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        $booking = $this->service->requestSeries(
            $f->id('id'),
            $f->id('room_id'),
            $f->text('title'),
            $this->pattern($request),
            $f->text('revision', 64)
        );
        return new JsonResponse(['id' => $booking->id->value, 'status' => $booking->status], 201);
    }
    private function pattern(ServerRequest $request): BookingPattern
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['pattern'] ?? null)) {
            throw new InvalidArgumentException('A booking pattern is required.');
        }
        $p = new FormInput($body['pattern']);
        return new BookingPattern(
            $p->text('from', 10),
            $p->text('through', 10),
            $p->text('start_time', 5),
            $p->text('end_time', 5),
            $p->values('weekdays', 7),
            $p->integer('interval_weeks', 1, 12),
            $p->values('excluded_dates', 100),
            $p->requiredBoolean('overnight'),
            $p->text('timezone', 80)
        );
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
            'occurrences' => array_map(static fn (BookingOccurrence $s): array => [
                'id' => $s->id->value, 'starts_at' => $s->time->startsAt->format(DATE_ATOM),
                'ends_at' => $s->time->endsAt->format(DATE_ATOM),
                'status' => $booking->status === 'cancelled' || $s->cancelledAt !== null
                    ? 'cancelled' : $booking->status,
                'cancelled_at' => $s->cancelledAt?->format(DATE_ATOM),
            ], $booking->occurrences)]);
    }

    public function cancelOccurrence(ServerRequest $request, string $id, string $occurrence): ResponseInterface
    {
        $booking = $this->service->cancelOccurrence(
            new EntityId($id),
            new EntityId($occurrence),
            FormInput::from($request)->integer('version')
        );
        return new JsonResponse(['id' => $id, 'status' => $booking->status, 'version' => $booking->version]);
    }
}
