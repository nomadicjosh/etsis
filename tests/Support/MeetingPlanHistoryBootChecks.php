<?php

declare(strict_types=1);

namespace Tests\Support;

use Application\Console\Commands\VerifyHistoryCommand;
use DateTimeImmutable;
use Domain\Scheduling\TimeSlot;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

// Fictional records and actual lazy providers. No institutional database, key or timetable is used.
$early = new CommandTester(new VerifyHistoryCommand($app));
$id = '00000000000000000000000001';
foreach (
    [
    ['--meeting-plan' => 'PRIVATE invalid input'],
    ['--meeting-plan' => $id, '--section' => $id],
    ['--meeting-plan' => $id, '--graduate-policy' => true],
    ['--meeting-plan' => $id, '--transcript-document' => $id],
    ['--meeting-plan' => $id, '--streams' => '10'],
    ['--meeting-plan' => $id, '--cursor' => 'PRIVATE cursor'],
    ['--meeting-plan' => $id, '--events' => '0'],
    ['--meeting-plan' => $id, '--events' => '2001'],
    ] as $options
) {
    if ($early->execute($options + ['--json' => true]) !== 2 || str_contains($early->getDisplay(), 'PRIVATE')) {
        throw new RuntimeException('Invalid meeting-plan options resolved storage or exposed private input.');
    }
}
require_once __DIR__ . '/AcademicTimetableFixture.php';
$f = new AcademicTimetableFixture();
$s = $f->sis;
$start = new DateTimeImmutable('2026-08-10T10:00:00-07:00');
$booking = $f->bookings->request(
    null,
    'PRIVATE fictional meeting purpose',
    [new TimeSlot($start, $start->modify('+1 hour'))],
    section: $f->section->id
);
$f->bookings->approve($booking->id, 0);
$f->publish();
$httpSis = $s;
$kernel->bootstrap();
require __DIR__ . '/SisHttpFixture.php';
$command = new CommandTester($kernel->all()['sis:history:verify']);
$before = array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
    'sis_section_meeting_plans', 'sis_bookings', 'sis_booking_slots', 'sis_audit', 'sis_enrollments']);
$s->connection->pdo->exec('PRAGMA query_only = ON');
$options = ['--meeting-plan' => $f->section->id->value];
if ($command->execute($options + ['--json' => true]) !== 0) {
    throw new RuntimeException('The actual meeting-plan verifier provider failed.');
}
$result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $result['scope'] !== 'section_meeting_plan' || $result['status'] !== 'verified'
    || $result['events_checked'] !== 2 || $result['current_meetings_checked'] !== 1
    || $result['records_changed'] || str_contains($command->getDisplay(), 'PRIVATE')
) {
    throw new RuntimeException('Meeting-plan JSON lost its minimized fixed observation.');
}
if (
    $command->execute($options) !== 0 || str_contains($command->getDisplay(), 'PRIVATE')
    || !str_contains($command->getDisplay(), 'No records were changed.')
) {
    throw new RuntimeException('Meeting-plan text output lost its privacy or no-change explanation.');
}
if (
    $command->execute($options + ['--events' => '1', '--json' => true]) !== 3
    || json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status'] !== 'limited'
) {
    throw new RuntimeException('Meeting-plan console source replay lost its shared bound.');
}
if (
    $before !== array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
    'sis_section_meeting_plans', 'sis_bookings', 'sis_booking_slots', 'sis_audit', 'sis_enrollments'])
) {
    throw new RuntimeException('Read-only meeting-plan verification changed source facts.');
}

// Connect the same verifier to the actual private browser routes; only support metadata may be written.
$s->connection->pdo->exec('PRAGMA query_only = OFF');
$s->clock->time = new DateTimeImmutable('2026-11-27T18:00:00Z');
$businessTables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
    'sis_section_meeting_plans', 'sis_bookings', 'sis_booking_slots', 'sis_enrollments', 'sis_academic_credits'];
$business = array_map($s->sql->rows(...), $businessTables);
$base = '/sis/history-checks/meetingplan/' . $f->section->id->value . '/';
$csrf = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base . 'run/'))
    ->withoutHeader('X-CSRF-Token')->withParsedBody([]);
if ($app->router->match($csrf)->getStatusCode() !== 403) {
    throw new RuntimeException('Meeting-plan support bypassed framework CSRF.');
}
$s->access->staff = false;
$duties = ['sis:history:verify', 'sis:audit:read', 'sis:catalog:manage', 'sis:scheduling:manage'];
foreach ($duties as $missing) {
    $s->access->grants = array_values(array_diff($duties, [$missing]));
    foreach (['run/', 'history/'] as $action) {
        if ($teachingPost($base . $action, [])->getStatusCode() !== 403) {
            throw new RuntimeException('Meeting-plan support bypassed an independent current duty.');
        }
    }
}
$s->access->grants = $duties;
$response = $teachingPost($base . 'run/', ['events' => 2000]);
$result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $response->getStatusCode() !== 200 || $result['event_limit'] !== 200
    || $result['result']['status'] !== 'verified' || $result['result']['events_checked'] !== 2
    || $result['result']['current_meetings_checked'] !== 1
    || !str_contains($response->getHeaderLine('Cache-Control'), 'private, no-store')
    || $response->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Meeting-plan browser support lost its bounded private result.');
}
foreach (['PRIVATE', '2026-08-10', 'booking_id', 'room_id', 'reason', 'definition'] as $private) {
    if (str_contains((string) $response->getBody(), $private)) {
        throw new RuntimeException('Meeting-plan support exposed a private source value.');
    }
}
$page = $teachingPost($base . 'history/', []);
$rows = json_decode((string) $page->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $page->getStatusCode() !== 200 || $rows['items'] !== [$result['receipt']]
    || !str_contains($page->getHeaderLine('Cache-Control'), 'private, no-store')
    || $page->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Meeting-plan support receipts lost their private routing.');
}
if ($business !== array_map($s->sql->rows(...), $businessTables)) {
    throw new RuntimeException('Meeting-plan support changed a publication, booking or academic source.');
}
