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
