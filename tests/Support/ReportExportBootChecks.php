<?php

declare(strict_types=1);

// Included by the isolated HTTP bootstrap only. No application .env or real student data.
$permissions = $app->make(\Domain\Access\Repository\AccessRepository::class);
$permissions->assign(
    \Domain\Access\Enum\GrantKind::UserRole,
    $sis->access->actor,
    $permissions->roleNamed('super')->id,
    true
);
$sis->student();
$report = \Domain\Shared\ValueObject\EntityId::generate();
$app->make(\Domain\Reporting\Service\RelationalReportService::class)->save(
    $report,
    \Domain\Shared\ValueObject\EntityId::generate(),
    -1,
    'Fictional complete export',
    [
        'dataset' => 'students', 'columns' => [
            ['field' => 'institutional_id', 'label' => 'Student ID', 'aggregate' => 'none'],
        ], 'joins' => [], 'filters' => null, 'having' => null, 'sort' => [], 'distinct' => false,
    ]
);
$events = $sis->sql->rows('sis_history_events');
$base = '/sis/report-studio/' . $report->value . '/exports/';
$id = \Domain\Shared\ValueObject\EntityId::generate();
$response = $teachingPost($base, ['operation_id' => $id->value, 'version' => 0]);
$data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 202 || ($data['export']['status'] ?? null) !== 'queued') {
    throw new RuntimeException('The private export request did not resolve through the real providers.');
}
$test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:reports:export']);
if (
    $test->execute(['--limit' => '0']) !== 2 || $test->execute([]) !== 0 ||
    !str_contains($test->getDisplay(), 'Preview only: 1 export jobs; 0 ready; 0 failed.')
) {
    throw new RuntimeException('The export command failed its bounded preview-only default.');
}
if (
    $test->execute(['--apply' => true]) !== 0 || !str_contains($test->getDisplay(), '1 ready; 0 failed.') ||
    str_contains($test->getDisplay(), $report->value) || str_contains($test->getDisplay(), 'Fictional')
) {
    throw new RuntimeException('The registered worker failed or exposed private references.');
}
$download = $teachingPost($base . $id->value . '/download/', []);
if (
    $download->getStatusCode() !== 200 || $download->getHeaderLine('Content-Type') !== 'text/csv; charset=UTF-8' ||
    !str_contains((string) $download->getBody(), 'Student ID') ||
    !str_contains($download->getHeaderLine('Content-Disposition'), 'attachment')
) {
    throw new RuntimeException('The private complete CSV response lost its transport protections.');
}
$cancelled = $teachingPost($base . $id->value . '/cancel/', []);
if ($cancelled->getStatusCode() !== 200 || $sis->sql->rows('sis_report_export_chunks') !== []) {
    throw new RuntimeException('Cancelling an export did not remove its private download.');
}
$second = \Domain\Shared\ValueObject\EntityId::generate();
// The same private report transport carries only the minimized receipt method.
$fundingAccount = \Domain\Finance\StudentAccount::open(
    new \Domain\Finance\ValueObject\AccountId(),
    $sis->student()->id,
    $sis->term,
    'USD'
);
$fundingAccount->post(
    \Domain\Shared\ValueObject\EntityId::generate(),
    \Domain\Finance\Enum\EntryType::Payment,
    new \Domain\Finance\ValueObject\Money(2500, 'USD'),
    $sis->access->actor,
    staffRecorded: true,
    paymentDetails: new \Domain\Finance\ValueObject\PaymentDetails(
        \Domain\Finance\Enum\PaymentMethod::Loan,
        'FICTIONAL-PRIVATE-REFERENCE'
    )
);
$sis->saveAccount($fundingAccount);
$events = $sis->sql->rows('sis_history_events');
$fundingDefinition = ['dataset' => 'postings', 'columns' => [
    ['field' => 'posting_payment_method', 'label' => 'Method', 'aggregate' => 'none'],
    ['field' => 'currency', 'label' => 'Currency', 'aggregate' => 'none'],
    ['field' => 'posting_amount', 'label' => 'Payments', 'aggregate' => 'sum'],
], 'filters' => ['condition' => 'AND', 'rules' => [
    ['id' => 'posting_type', 'operator' => 'equal', 'value' => 'payment'],
    ['id' => 'posting_reversed', 'operator' => 'equal', 'value' => false],
]]];
$fundingDefinition['columns'][] = [
    'field' => 'posting_recorded_at', 'label' => 'Posting month UTC',
    'aggregate' => 'none', 'date_bucket' => 'month',
];
$fundingPreview = $teachingPost('/sis/report-studio/preview/', ['definition' => $fundingDefinition, 'offset' => 0]);
$fundingData = json_decode((string) $fundingPreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $fundingPreview->getStatusCode() !== 200 || ($fundingData['items'][0]['c0'] ?? null) !== 'loan' ||
    preg_match('/^\d{4}-\d{2}$/D', (string) ($fundingData['items'][0]['c3'] ?? '')) !== 1 ||
    !str_contains($fundingPreview->getHeaderLine('Cache-Control'), 'no-store') ||
    $fundingPreview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The private finance report did not resolve its receipt-method projection.');
}
$fundingReport = \Domain\Shared\ValueObject\EntityId::generate()->value;
$fundingSaved = $teachingPost('/sis/report-studio/save/', ['report_id' => $fundingReport,
    'operation_id' => $fundingReport, 'version' => -1, 'name' => 'Fictional funding methods',
    'definition' => $fundingDefinition]);
$fundingCsv = $teachingPost('/sis/report-studio/' . $fundingReport . '/export/', ['offset' => 0]);
if (
    $fundingSaved->getStatusCode() !== 201 || $fundingCsv->getStatusCode() !== 200 ||
    !str_contains((string) $fundingCsv->getBody(), 'loan') ||
    str_contains((string) $fundingCsv->getBody(), 'FICTIONAL-PRIVATE-REFERENCE') ||
    $events !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Receipt-method reporting exposed private references or changed financial history.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:reporting:run', 'sis:student:read'];
if ($teachingPost('/sis/report-studio/' . $fundingReport . '/export/', ['offset' => 0])->getStatusCode() !== 403) {
    throw new RuntimeException('Receipt-method export bypassed independent finance source permission.');
}
$sis->access->staff = true;
$sis->access->grants = [];
$teachingPost($base, ['operation_id' => $second->value, 'version' => 0]);
$sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $sis->access->actor->value]);
if (
    $test->execute(['--apply' => true]) !== 1 || !str_contains($test->getDisplay(), '0 ready; 1 failed.') ||
    $sis->sql->rows('sis_report_export_chunks') !== [] || $sis->sql->rows('sis_history_events') !== $events
) {
    throw new RuntimeException('Disabled sign-in retained export authority or changed academic history.');
}
