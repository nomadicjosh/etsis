<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\BookingCalendarService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisBookingCalendarController
{
    public function __construct(private BookingCalendarService $calendar)
    {
    }

    public function context(): ResponseInterface
    {
        return new JsonResponse($this->calendar->context());
    }

    public function week(ServerRequest $request): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->calendar->week(
            $f->optionalText('week', 10),
            $f->optionalId('building_id'),
            $f->search('search')
        ));
    }
}
