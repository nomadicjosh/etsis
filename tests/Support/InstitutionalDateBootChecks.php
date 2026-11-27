<?php

declare(strict_types=1);

// Real providers, commands and private/CSRF transport over fictional in-memory records.
$sis->gradeScale([['code' => 'A', 'min_score_milli' => 0, 'max_score_milli' => 100000,
    'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true]]);
$student = $sis->student()->id;
$section = $sis->section(fee: 0, faculty: $sis->access->actor)->id;
$enrollment = $sis->service->register($student, $section)->id;
$grade = $sis->academicCredits()->choices($student, $section, $enrollment)['items'][0]['id'];
$review = $sis->academicCredits()->review(
    $student,
    $section,
    $enrollment,
    new \Domain\Shared\ValueObject\EntityId($grade),
    0
);
$sis->academicCredits()->commit($student, $section, $review['review_token']);
$credit = $sis->sql->one('sis_academic_credits', ['source_id' => $enrollment->value])['id'];
$dates = $app->make(\Domain\AcademicRecord\Service\AcademicDetailsService::class);
$review = $dates->review($student, $section, $enrollment, '2026-07-15', 1, 'Fictional academic evidence.');
$dates->commit($student, $section, $review['review_token']);
$definition = ['dataset' => 'credits', 'columns' => [
    ['field' => 'credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
    ['field' => 'institutional_completed_on', 'label' => 'Completion', 'aggregate' => 'none'],
]];
$unsafe = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid/sis/report-studio/preview/'))
    ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$unsafe->getBody()->write(http_build_query(['_workspace_json' => json_encode(['definition' => $definition])]));
$unsafe->getBody()->rewind();
if ($app->router->match($unsafe)->getStatusCode() !== 403) {
    throw new RuntimeException('Institutional dates bypassed framework CSRF.');
}
$preview = $teachingPost('/sis/report-studio/preview/', ['definition' => $definition, 'offset' => 0]);
$rows = json_decode((string) $preview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $preview->getStatusCode() !== 200 || ($rows['items'][0]['c1'] ?? null) !== '2026-07-15' ||
    !str_contains($preview->getHeaderLine('Cache-Control'), 'no-store') || $preview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Actual date writer/report providers lost private projected completion.');
}
$report = \Domain\Shared\ValueObject\EntityId::generate()->value;
$saved = $teachingPost('/sis/report-studio/save/', ['report_id' => $report, 'operation_id' => $report,
    'version' => -1, 'name' => 'Fictional institutional chronology', 'definition' => $definition]);
$csv = $teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0]);
if (
    $saved->getStatusCode() !== 201 || $csv->getStatusCode() !== 200 ||
    !str_contains((string) $csv->getBody(), '2026-07-15')
) {
    throw new RuntimeException('Actual saved institutional dates lost CSV fidelity.');
}
$sis->sql->execute('DELETE FROM sis_academic_date_index WHERE id = ?', [$credit]);
if (
    $teachingPost('/sis/report-studio/preview/', ['definition' => $definition, 'offset' => 0])
    ->getStatusCode() !== 422 || $teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0])
    ->getStatusCode() !== 422
) {
    throw new RuntimeException('Missing known date coverage returned partial live reports or CSV.');
}
$tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_academic_credits'];
$before = array_map($sis->sql->rows(...), $tables);
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:projections:academic-dates']);
$sis->connection->pdo->exec('PRAGMA query_only = ON');
if (
    $command->execute(['--credit' => $credit, '--json' => true]) !== 3 ||
    json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status'] !== 'needs_reconciliation'
) {
    throw new RuntimeException('Default academic date inspection wrote data or failed to inspect.');
}
$sis->connection->pdo->exec('PRAGMA query_only = OFF');
foreach (
    [
    [['--credit' => $credit, '--events' => '1', '--apply' => true], 3, 'limited'],
    [['--credit' => 'Private invalid input'], 2, 'invalid_input'],
    [['--credit' => $credit, '--after' => $credit], 2, 'invalid_input'],
    [['--credit' => $credit, '--apply' => true], 0, 'applied'],
    [['--credit' => $credit], 0, 'ready'],
    ] as [$options, $exit, $status]
) {
    if (
        $command->execute($options + ['--json' => true]) !== $exit ||
        json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['status'] !== $status ||
        str_contains($command->getDisplay(), 'Private invalid input')
    ) {
        throw new RuntimeException('Actual reconciliation command lost its bounded/private result contract.');
    }
}
if ($before !== array_map($sis->sql->rows(...), $tables)) {
    throw new RuntimeException('Date reconciliation changed business facts or historical events.');
}
if (
    $teachingPost('/sis/report-studio/preview/', ['definition' => $definition, 'offset' => 0])
    ->getStatusCode() !== 200
) {
    throw new RuntimeException('Deliberate reconciliation did not restore report readiness.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:reporting:manage'];
if (
    $teachingPost('/sis/report-studio/preview/', ['definition' => $definition, 'offset' => 0])
    ->getStatusCode() !== 403
) {
    throw new RuntimeException('Institutional dates bypassed independent student-data permission.');
}
