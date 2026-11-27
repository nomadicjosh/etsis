<?php

declare(strict_types=1);

// Actual report providers and private transport; fictional current contact projections only.
$student = $sis->student();
$person = $sis->admissions->person($student->personId);
$sis->sql->update(
    'users',
    ['email' => 'fictional-contact@example.invalid'],
    ['user_id' => $person->ownerUserId->value]
);
$definition = ['dataset' => 'students', 'columns' => [
    ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
    ['field' => 'current_account_email', 'label' => 'Contact', 'aggregate' => 'none'],
], 'filters' => ['condition' => 'AND', 'rules' => [
    ['id' => 'student_id', 'operator' => 'equal', 'value' => $student->id->value],
]]];
$events = $sis->sql->rows('sis_history_events');
$unsafe = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/sis/report-studio/preview/'))
    ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$unsafe->getBody()->write(http_build_query(['_workspace_json' => json_encode(['definition' => $definition])]));
$unsafe->getBody()->rewind();
if ($app->router->match($unsafe)->getStatusCode() !== 403) {
    throw new RuntimeException('Contact report bypassed framework CSRF.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:student:read'];
$graph = $teachingPost('/sis/report-studio/graph/', ['dataset' => 'students', 'aliases' => []]);
if (
    $graph->getStatusCode() !== 200 || str_contains((string) $graph->getBody(), 'current_account_email') ||
    str_contains((string) $graph->getBody(), 'account_contact')
) {
    throw new RuntimeException('Contact report metadata bypassed its independent data duty.');
}
$denied = $teachingPost('/sis/report-studio/preview/', ['definition' => $definition, 'offset' => 0]);
if ($denied->getStatusCode() !== 403 || str_contains((string) $denied->getBody(), 'fictional-contact@')) {
    throw new RuntimeException('Contact data leaked without its independent duty.');
}
$sis->access->grants[] = 'sis:reporting:contact';
$preview = $teachingPost('/sis/report-studio/preview/', ['definition' => $definition, 'offset' => 0]);
$rows = json_decode((string) $preview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $preview->getStatusCode() !== 200 || ($rows['items'][0]['c1'] ?? null) !== 'fictional-contact@example.invalid' ||
    !str_contains($preview->getHeaderLine('Cache-Control'), 'no-store') || $preview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Actual contact source lost its private canonical-account result.');
}
$report = \Domain\Shared\ValueObject\EntityId::generate()->value;
$saved = $teachingPost('/sis/report-studio/save/', ['report_id' => $report, 'operation_id' => $report,
    'version' => -1, 'name' => 'Fictional student contacts', 'definition' => $definition]);
$csv = $teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0]);
if (
    $saved->getStatusCode() !== 201 || $csv->getStatusCode() !== 200 ||
    !str_contains((string) $csv->getBody(), 'fictional-contact@example.invalid') ||
    !str_contains($csv->getHeaderLine('Cache-Control'), 'no-store') || $csv->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Saved contact CSV lost its current data or private response policy.');
}
$sis->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:student:read'];
if ($teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0])->getStatusCode() !== 403) {
    throw new RuntimeException('Revoked contact authority left a saved CSV readable.');
}
if ($sis->sql->rows('sis_history_events') !== $events) {
    throw new RuntimeException('Contact reporting appended business history.');
}
