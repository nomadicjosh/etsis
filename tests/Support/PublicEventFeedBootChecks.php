<?php

declare(strict_types=1);

// Actual providers/routes and transport; fictional SQLite only, with no external calendar requests.
$sis->clock->time = new DateTimeImmutable('2026-08-10T10:00:00-07:00');
$at = new DateTimeImmutable('2026-08-11T01:30:00Z');
$booking = new \Domain\Scheduling\Booking(
    \Domain\Shared\ValueObject\EntityId::generate(),
    null,
    $sis->access->actor,
    'PRIVATE joining credentials',
    [\Domain\Scheduling\BookingOccurrence::plan(new \Domain\Scheduling\TimeSlot($at, $at->modify('+1 hour')))],
    'approved',
);
new \Infrastructure\Persistence\Repository\SqlBookingRepository($sis->sql)->add($booking);
$service = $app->make(\Domain\Scheduling\Service\PublicEventService::class);
$review = $service->review($booking->id, 'Fictional public open house', 'Explicit public invitation.');
$service->publish($booking->id, $review['review_token']);
$tables = ['sis_bookings', 'sis_booking_slots', 'sis_public_events', 'sis_public_event_times',
    'sis_audit', 'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
$before = array_map($sis->sql->rows(...), $tables);
$portal = $app->configContainer->getConfigKey('portal');
$app->configContainer->setConfigKey('app.url', 'https://campus.example.invalid');
$app->configContainer->setConfigKey('portal', array_replace($portal, [
    'public_event_feed' => ['enabled' => true],
]));
$sis->access->staff = false;
$request = $get->withUri(new \Laminas\Diactoros\Uri('https://untrusted.example.invalid/portal/events/calendar.ics'))
    ->withHeader('Accept', 'text/calendar')->withHeader('X-Forwarded-Host', 'PRIVATE.invalid');
$response = $app->router->match($request);
$body = str_replace("\r\n ", '', (string) $response->getBody());
if (
    $response->getStatusCode() !== 200 || $response->getHeaderLine('Content-Type') !== 'text/calendar; charset=UTF-8'
    || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') || $response->hasHeader('phpdebugbar-id')
    || !str_contains($body, 'SUMMARY:Fictional public open house')
    || !str_contains($body, 'https://campus.example.invalid/portal/events/?date=2026-08-10')
    || str_contains($body, 'PRIVATE') || str_contains($body, 'untrusted.example.invalid')
    || str_contains($body, $booking->id->value)
) {
    throw new RuntimeException(
        'The actual public feed lost its publication, canonical URL or private transport boundary.'
    );
}
$head = $app->router->match($request->withMethod('HEAD'));
if ($head->getStatusCode() !== 200 || (string) $head->getBody() !== '') {
    throw new RuntimeException('The actual public feed HEAD response contained a body or lost its route.');
}
$page = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/portal/events/?date=2026-08-10'
))->withQueryParams(['date' => '2026-08-10'])->withHeader('Accept', 'text/html'));
if ($page->getStatusCode() !== 200 || !str_contains((string) $page->getBody(), 'public-event-feed-address')) {
    throw new RuntimeException('The enabled public feed was not connected to the actual calendar page.');
}
$app->configContainer->setConfigKey('portal', array_replace($portal, ['public_event_feed' => ['enabled' => false]]));
if ($app->router->match($request)->getStatusCode() !== 404) {
    throw new RuntimeException('The disabled public feed remained accessible.');
}
$app->configContainer->setConfigKey('portal', array_replace($portal, [
    'public_event_feed' => ['enabled' => 'PRIVATE'],
]));
$invalid = $app->router->match($request);
if ($invalid->getStatusCode() !== 503 || str_contains((string) $invalid->getBody(), 'PRIVATE')) {
    throw new RuntimeException('Malformed public feed policy lost its fixed unavailable response.');
}
if ($before !== array_map($sis->sql->rows(...), $tables)) {
    throw new RuntimeException('A public calendar feed read changed recorded facts.');
}
