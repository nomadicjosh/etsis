<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Service\PublicEventFeedSettings;
use Application\View\PublicEventCalendar;
use Domain\Scheduling\Service\PublicEventService;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class CampusEventFeedController
{
    public function __construct(
        private PublicEventFeedSettings $settings,
        private PublicEventService $events,
        private PublicEventCalendar $calendar,
    ) {
    }

    public function feed(ServerRequest $request): ResponseInterface
    {
        $head = $request->getMethod() === 'HEAD';
        try {
            $url = $this->settings->url();
            if ($url === null) {
                return new TextResponse($head ? '' : trans('The public calendar feed is not enabled.'), 404, [
                    'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
                ]);
            }
            $body = $this->calendar->render($this->events->calendarFeed(), $url);
            if ($this->settings->url() !== $url) {
                throw new \RuntimeException('Public sharing changed before completion.');
            }
            return new TextResponse($head ? '' : $body, 200, [
                'Content-Type' => 'text/calendar; charset=UTF-8',
                'Content-Length' => (string) strlen($body),
                'Content-Disposition' => 'attachment; filename="campus-events.ics"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable) {
            // A complete calendar or a fixed retry response, never a partial feed or upstream error.
            $message = trans('The public calendar feed is unavailable. Try again later.');
            return new TextResponse($head ? '' : $message, 503, [
                'Retry-After' => '300', 'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }
}
