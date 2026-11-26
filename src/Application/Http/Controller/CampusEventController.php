<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Application\View\CampusRenderer;
use Domain\Scheduling\Service\PublicEventService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Qubus\View\Native\NativeLoader;

final readonly class CampusEventController
{
    public function __construct(
        private PublicEventService $events,
        private CampusRenderer $renderer,
        private \Application\Service\PublicEventFeedSettings $feed,
    ) {
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        return $this->renderer->render('events', 'Find your place on campus', $this->calendarData($request));
    }

    public function calendar(ServerRequest $request): ResponseInterface
    {
        $loader = new NativeLoader(['framework' => dirname(__DIR__, 4) . '/resources/views']);
        return new JsonResponse(['html' => $loader->render(
            'framework::frontend/portal/components/event-calendar',
            $this->calendarData($request)
        )]);
    }

    /** @return array<string,mixed> */
    private function calendarData(ServerRequest $request): array
    {
        $f = new FormInput($request->getQueryParams());
        $search = $f->search('search', 80);
        return ['publicEventFeedUrl' => $this->feed->visibleUrl(), 'calendar' => $this->events->calendar(
            $f->optionalText('date', 10),
            $f->optionalText('view', 10) ?? 'month',
            $search
        ), 'search' => $search,
            'calendarDate' => $f->optionalText('date', 10) ?? $this->events->context()['today'],
            'selectedDay' => $f->optionalText('day', 10),
            'dailyPage' => isset($request->getQueryParams()['page']) ? $f->integer('page', 0, 24) : 0];
    }

    public function setup(string $id): ResponseInterface
    {
        return new JsonResponse($this->events->setup(new EntityId($id)));
    }

    public function announcement(string $id): ResponseInterface
    {
        return new JsonResponse($this->events->announcement(new EntityId($id)));
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->events->review(
            new EntityId($id),
            $f->text('title'),
            $f->bodyText('description')
        ));
    }

    public function publish(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->events->publish(
            new EntityId($id),
            FormInput::from($request)->text('review_token', 262144)
        ));
    }

    public function withdraw(ServerRequest $request, string $id): ResponseInterface
    {
        $this->events->withdraw(new EntityId($id), FormInput::from($request)->integer('version', 1));
        return new JsonResponse(['status' => 'withdrawn']);
    }
}
