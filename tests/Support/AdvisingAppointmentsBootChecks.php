<?php

declare(strict_types=1);

// Fictional scheduling through actual providers, private transport and framework CSRF.
$appointmentBase = '/sis/students/' . $advisingStudent->id->value . '/programs/' . $advisingPursuit . '/appointments/';
$appointmentPage = $checkInPost($appointmentBase . 'list/', []);
if (!($appointmentPage['can_schedule'] ?? false)) {
    throw new RuntimeException('The appointment providers did not resolve eligible assigned hosts.');
}
$appointmentDay = $sis->clock->now()->modify('+1 day')->format('Y-m-d');
$appointmentCommand = ['appointment_id' => $appointmentPage['new_id'],
    'host_person_id' => $advisingPerson->id->value, 'start' => $appointmentDay . 'T10:00',
    'end' => $appointmentDay . 'T10:30', 'timezone' => $appointmentPage['timezone'],
    'message' => 'Fictional meeting instructions.'];
$appointmentHistory = $sis->sql->rows('sis_history_events');
$appointmentCreated = $checkInPost($appointmentBase . 'schedule/', $appointmentCommand);
$checkInPost($appointmentBase . 'schedule/', $appointmentCommand);
$appointmentOwnerBase = '/portal/api/students/' . $advisingStudent->id->value . '/programs/'
. $advisingPursuit . '/appointments/';
if ($teachingPost($appointmentOwnerBase . 'list/', [])->getStatusCode() !== 403) {
    throw new RuntimeException('Office scheduling privileges bypassed owner-only appointment reads.');
}
$sis->access->actor = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
$appointmentOwnerPage = $checkInPost($appointmentOwnerBase . 'list/', []);
if (
    count($appointmentOwnerPage['items']) !== 1 || $appointmentOwnerPage['hosts'] !== []
    || str_contains(json_encode($appointmentOwnerPage), 'actor_id')
) {
    throw new RuntimeException('Owner appointment privacy or retained time failed.');
}
$checkInPost($appointmentOwnerBase . 'act/', ['appointment_id' => $appointmentCreated['id'],
    'operation_id' => $appointmentOwnerPage['items'][0]['new_operation'], 'version' => 0,
    'action' => 'cancel', 'message' => 'Fictional student cancellation.']);
if ($appointmentHistory !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('Scheduling fabricated authoritative academic decisions.');
}
$appointmentCsrf = $get->withMethod('POST')->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $appointmentOwnerBase . 'list/'
))->withoutHeader('X-CSRF-Token')->withParsedBody([]);
if ($app->router->match($appointmentCsrf)->getStatusCode() !== 403) {
    throw new RuntimeException('Appointment reads bypassed framework CSRF.');
}
$sis->access->actor = $advisingActor;
$sis->access->staff = true;

// Calendar summaries omit conversation text; owner and correction routes keep their separate duties.
$appointmentCalendar = $checkInPost($appointmentBase . 'calendar/', ['date' => $appointmentDay,
    'include_cancelled' => true]);
if (
    count($appointmentCalendar['items']) !== 1 || str_contains(
        json_encode($appointmentCalendar),
        'Fictional meeting instructions.'
    )
) {
    throw new RuntimeException('The minimized appointment calendar did not resolve through real providers.');
}
$sis->access->actor = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
$mine = $checkInPost('/portal/api/advising-appointments/calendar/', ['date' => $appointmentDay,
    'include_cancelled' => true]);
$exact = $checkInPost($appointmentOwnerBase . 'list/', ['appointment_id' => $appointmentCreated['id']]);
if (count($mine['items']) !== 1 || $exact['items'][0]['status'] !== 'cancelled' || $exact['can_schedule']) {
    throw new RuntimeException('The exact finished owner appointment or independent calendar failed.');
}
$sis->access->actor = $advisingActor;
$newAppointment = $checkInPost($appointmentBase . 'schedule/', array_replace($appointmentCommand, [
    'appointment_id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
]));
$oldAppointmentClock = $sis->clock->time;
$sis->clock->time = new \DateTimeImmutable($appointmentDay . 'T11:00:00-07:00');
$checkInPost($appointmentBase . 'act/', ['appointment_id' => $newAppointment['id'],
    'operation_id' => \Domain\Shared\ValueObject\EntityId::generate()->value, 'version' => 0,
    'action' => 'held', 'message' => 'Fictional attendance.']);
$correction = ['appointment_id' => $newAppointment['id'],
    'operation_id' => \Domain\Shared\ValueObject\EntityId::generate()->value, 'version' => 1,
    'action' => 'correct_no_show', 'message' => 'Fictional corrected attendance.'];
$sis->access->staff = false;
$sis->access->grants = ['sis:student:read', 'sis:advising:appointments:manage'];
if ($teachingPost($appointmentBase . 'act/', $correction)->getStatusCode() !== 403) {
    throw new RuntimeException('Attendance correction bypassed its independent office duty.');
}
$sis->access->grants[] = 'sis:advising:appointments:correct';
$checkInPost($appointmentBase . 'act/', $correction);
$checkInPost($appointmentBase . 'act/', $correction);
$sis->access->staff = true;
$sis->clock->time = $oldAppointmentClock;
foreach ([$appointmentBase . 'calendar/', '/portal/api/advising-appointments/calendar/'] as $calendarPath) {
    $csrfProbe = $get->withMethod('POST')->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $calendarPath
    ))->withoutHeader('X-CSRF-Token')->withParsedBody([]);
    if ($app->router->match($csrfProbe)->getStatusCode() !== 403) {
        throw new RuntimeException('Appointment calendar reads bypassed framework CSRF.');
    }
}
if ($appointmentHistory !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('An attendance correction fabricated an authoritative academic event.');
}
