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
$sis->access->grants = ['sis:reporting:run'];
if ($teachingPost('/sis/report-studio/' . $report . '/export/', ['offset' => 0])->getStatusCode() !== 403) {
    throw new RuntimeException('A calculated CSV bypassed its current source duty.');
}
if ($sis->sql->rows('sis_history_events') !== $events) {
    throw new RuntimeException('Calculated reporting changed business history.');
}
