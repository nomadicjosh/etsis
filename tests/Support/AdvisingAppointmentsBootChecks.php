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
