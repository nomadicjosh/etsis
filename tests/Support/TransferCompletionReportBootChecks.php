<?php

declare(strict_types=1);

// Actual providers and CSRF/private transport; only fictional SQLite records.
require_once __DIR__ . '/TransferAwardFixture.php';
$transferDates = new TransferAwardFixture($sis, $sis->dropCipher);
$dateReview = $transferDates->periods()->review(
    $transferDates->student,
    $transferDates->award,
    '2026-01-12',
    0,
    'Fictional verified transcript dates.',
    '2026-06-12',
    '2026-08-10'
);
$transferDates->periods()->commit($transferDates->student, $transferDates->award, $dateReview['review_token']);
$dateEvents = $sis->sql->rows('sis_history_events');
$dateDefinition = ['dataset' => 'credits', 'columns' => [
    ['field' => 'credit_id', 'label' => 'Credit', 'aggregate' => 'none'],
    ['field' => 'transfer_completed_on', 'label' => 'Current completion', 'aggregate' => 'none'],
    ['field' => 'original_transfer_completed_on', 'label' => 'Original completion', 'aggregate' => 'none'],
]];
$dateUnsafe = $post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/report-studio/preview/'
))->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$dateUnsafe->getBody()->write(http_build_query(['_workspace_json' => json_encode([
    'definition' => $dateDefinition, 'offset' => 0,
])]));
$dateUnsafe->getBody()->rewind();
if ($app->router->match($dateUnsafe)->getStatusCode() !== 403) {
    throw new RuntimeException('The completion-date report bypassed framework CSRF.');
}
$datePreview = $teachingPost('/sis/report-studio/preview/', ['definition' => $dateDefinition, 'offset' => 0]);
$dateRows = json_decode((string) $datePreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $datePreview->getStatusCode() !== 200 || ($dateRows['items'][0]['c1'] ?? null) !== '2026-06-12' ||
    ($dateRows['items'][0]['c2'] ?? null) !== '2026-05-01' ||
    !str_contains($datePreview->getHeaderLine('Cache-Control'), 'no-store') ||
    $datePreview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The actual private report lost its corrected/original transfer dates.');
}
$dateGraphResponse = $teachingPost('/sis/report-studio/graph/', ['dataset' => 'students', 'aliases' => []]);
$dateGraph = json_decode((string) $dateGraphResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $dateGraphResponse->getStatusCode() !== 200 || !isset(
        $dateGraph['dataset']['relationships']['academic_credit']['date_fields']['recorded_transfer_completed_on']
    )
) {
    throw new RuntimeException('The actual report metadata omitted whole-row completion-date selection.');
}
$dateReport = \Domain\Shared\ValueObject\EntityId::generate()->value;
$dateSaved = $teachingPost('/sis/report-studio/save/', ['report_id' => $dateReport,
    'operation_id' => $dateReport, 'version' => -1, 'name' => 'Fictional transfer chronology',
    'definition' => $dateDefinition]);
$dateCsv = $teachingPost('/sis/report-studio/' . $dateReport . '/export/', ['offset' => 0]);
if (
    $dateSaved->getStatusCode() !== 201 || $dateCsv->getStatusCode() !== 200 ||
    !str_contains((string) $dateCsv->getBody(), '2026-06-12') ||
    !str_contains((string) $dateCsv->getBody(), '2026-05-01') ||
    $sis->sql->rows('sis_history_events') !== $dateEvents
) {
    throw new RuntimeException('Saved completion dates lost CSV fidelity or changed academic history.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:reporting:manage'];
if (
    $teachingPost('/sis/report-studio/preview/', ['definition' => $dateDefinition, 'offset' => 0])
    ->getStatusCode() !== 403 || $teachingPost('/sis/report-studio/' . $dateReport . '/export/', ['offset' => 0])
    ->getStatusCode() !== 403
) {
    throw new RuntimeException('The completion-date report bypassed current student-data permission.');
}
