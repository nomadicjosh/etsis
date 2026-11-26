<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Scheduling\Service\AcademicTimetableService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class PortalTimetableController
{
    public function __construct(
        private AcademicTimetableService $timetable,
        private \Application\Service\AcademicTimetableExport $export,
    ) {
    }

    public function context(): ResponseInterface
    {
        return new JsonResponse($this->timetable->context());
    }

    public function terms(ServerRequest $request): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->timetable->terms($form->search('search'), $form->optionalId('after')));
    }

    public function view(ServerRequest $request): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->timetable->timetable(
            $form->id('term_id'),
            $form->optionalText('date', 10),
            $form->optionalText('view', 5) ?? 'week'
        ));
    }

    public function export(ServerRequest $request): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse(['calendar' => $this->export->prepare(
            $form->id('term_id'),
            $form->optionalText('date', 10),
            $form->optionalText('view', 5) ?? 'week',
        )]);
    }
}
