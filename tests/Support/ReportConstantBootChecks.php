<?php

declare(strict_types=1);

// Exercise actual providers, framework CSRF, decimal result typing and the existing private CSV endpoint.
$section = $sis->section(25, 0);
$definition = ['dataset' => 'sections', 'columns' => [
    ['field' => 'section_code', 'label' => 'Section', 'aggregate' => 'none'],
    ['field' => 'capacity', 'label' => 'Fictional planning units', 'aggregate' => 'none',
        'calculation' => ['operator' => '/', 'value' => '2']],
]];
$events = $sis->sql->rows('sis_history_events');
$path = '/sis/report-studio/preview/';
$unsafe = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path))
    ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$unsafe->getBody()->write(http_build_query(['_workspace_json' => json_encode(['definition' => $definition])]));
$unsafe->getBody()->rewind();
if ($app->router->match($unsafe)->getStatusCode() !== 403) {
    throw new RuntimeException('A calculated report bypassed framework CSRF.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:reporting:manage', 'sis:reporting:run', 'sis:catalog:manage'];
$preview = $teachingPost($path, ['definition' => $definition, 'offset' => 0]);
$rows = json_decode((string) $preview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $preview->getStatusCode() !== 200 || ($rows['items'][0]['c1'] ?? null) !== 12.5 ||
    !str_contains($preview->getHeaderLine('Cache-Control'), 'no-store') || $preview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('A calculated preview lost its typed private result.');
}
$invalid = $definition;
$invalid['columns'][1]['calculation']['value'] = '1e3';
$invalidResponse = $teachingPost($path, ['definition' => $invalid, 'offset' => 0]);
if ($invalidResponse->getStatusCode() !== 422) {
    throw new RuntimeException('An untyped decimal reached report execution.');
}
$report = \Domain\Shared\ValueObject\EntityId::generate()->value;
$saved = $teachingPost('/sis/report-studio/save/', ['report_id' => $report, 'operation_id' => $report,
    'version' => -1, 'name' => 'Fictional decimal calculation', 'definition' => $definition]);
$csv = $teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0]);
if (
    $saved->getStatusCode() !== 201 || $csv->getStatusCode() !== 200 ||
    !str_contains((string) $csv->getBody(), '12.5') ||
    !str_contains($csv->getHeaderLine('Cache-Control'), 'no-store') || $csv->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('A calculated CSV lost its saved result or private response.');
}
$grouped = $definition;
$grouped['columns'][1]['calculation'] = ['operator' => '/',
    'left' => ['group' => ['operator' => '-', 'left' => ['field' => 'capacity'], 'right' => ['value' => '4']]],
    'group' => ['operator' => '+', 'left' => ['value' => '2'], 'right' => ['value' => '2']]];
$groupPreview = $teachingPost($path, ['definition' => $grouped, 'offset' => 0]);
$groupRows = json_decode((string) $groupPreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($groupPreview->getStatusCode() !== 200 || ($groupRows['items'][0]['c1'] ?? null) !== 5.25) {
    throw new RuntimeException('A grouped calculation lost parentheses or typed results.');
}
$groupReport = \Domain\Shared\ValueObject\EntityId::generate()->value;
$groupSaved = $teachingPost('/sis/report-studio/save/', ['report_id' => $groupReport, 'operation_id' => $groupReport,
    'version' => -1, 'name' => 'Fictional grouped calculation', 'definition' => $grouped]);
$groupCsv = $teachingPost('/sis/report-studio/' . $groupReport . '/export/', ['offset' => 0]);
if (
    $groupSaved->getStatusCode() !== 201 || $groupCsv->getStatusCode() !== 200
    || !str_contains((string) $groupCsv->getBody(), '5.25')
    || !str_contains($groupCsv->getHeaderLine('Cache-Control'), 'no-store') || $groupCsv->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Grouped calculations lost their saved private CSV workflow.');
}
$grouped['columns'][1]['calculation']['left']['group']['right']['value'] = '1e3';
if ($teachingPost($path, ['definition' => $grouped, 'offset' => 0])->getStatusCode() !== 422) {
    throw new RuntimeException('A hidden untyped operand reached the actual query route.');
}
$nulls = $definition;
$nulls['columns'][1]['calculation'] = ['operator' => 'coalesce',
    'left' => ['group' => ['operator' => 'nullif', 'left' => ['field' => 'capacity'], 'right' => ['value' => '25']]],
    'value' => '7.25'];
$nullPreview = $teachingPost($path, ['definition' => $nulls, 'offset' => 0]);
$nullRows = json_decode((string) $nullPreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $nullPreview->getStatusCode() !== 200 || ($nullRows['items'][0]['c1'] ?? null) !== 7.25
    || !str_contains($nullPreview->getHeaderLine('Cache-Control'), 'no-store')
    || $nullPreview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Explicit missing-value calculations lost their private typed result.');
}
$nullReport = \Domain\Shared\ValueObject\EntityId::generate()->value;
$nullSaved = $teachingPost('/sis/report-studio/save/', ['report_id' => $nullReport, 'operation_id' => $nullReport,
    'version' => -1, 'name' => 'Fictional missing-value calculation', 'definition' => $nulls]);
$nullCsv = $teachingPost('/sis/report-studio/' . $nullReport . '/export/', ['offset' => 0]);
if (
    $nullSaved->getStatusCode() !== 201 || $nullCsv->getStatusCode() !== 200
    || !str_contains((string) $nullCsv->getBody(), '7.25')
    || !str_contains($nullCsv->getHeaderLine('Cache-Control'), 'no-store') || $nullCsv->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Explicit missing-value calculations lost their saved private CSV.');
}
$nulls['columns'][1]['calculation']['left']['group']['operator'] = 'NULLIF;SELECT 1';
if ($teachingPost($path, ['definition' => $nulls, 'offset' => 0])->getStatusCode() !== 422) {
    throw new RuntimeException('An unsupported hidden numeric operation reached SQL.');
}
$sis->access->grants = ['sis:reporting:run'];
if ($teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0])->getStatusCode() !== 403) {
    throw new RuntimeException('A calculated CSV bypassed its current source duty.');
}
if ($sis->sql->rows('sis_history_events') !== $events) {
    throw new RuntimeException('Calculated reporting changed business history.');
}
