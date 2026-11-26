<?php

declare(strict_types=1);

// Actual providers and CSRF-protected private owner read; all source facts are fictional SQLite.
$facts = $calendarFixture->facts();
$before = count($sis->sql->rows('sis_audit'));
$path = '/portal/api/timetable/export/';
$response = $teachingPost($path, ['term_id' => $sis->term->value, 'date' => '2026-08-10', 'view' => 'week']);
$result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $response->getStatusCode() !== 200 || !str_contains($result['calendar'] ?? '', 'CLASS:PRIVATE')
    || str_contains($result['calendar'], 'PRIVATE booking')
    || str_contains($result['calendar'], $sis->access->actor->value)
    || str_contains($result['calendar'], $calendarFixture->school->section->id->value)
    || $facts !== $calendarFixture->facts() || count($sis->sql->rows('sis_audit')) !== $before + 1
) {
    throw new RuntimeException('Private timetable export lost its owner, source, audit or minimized output boundary.');
}
$sis->access->actor = $sis->user();
$sis->access->staff = true;
$response = $teachingPost($path, ['term_id' => $sis->term->value, 'date' => '2026-08-10', 'view' => 'week']);
if ($response->getStatusCode() !== 403 || str_contains((string) $response->getBody(), 'BEGIN:VCALENDAR')) {
    throw new RuntimeException('Staff authority exported another account timetable.');
}
