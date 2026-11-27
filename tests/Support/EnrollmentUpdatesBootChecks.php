<?php

declare(strict_types=1);

// Actual private providers/routes and framework CSRF; all records and accounts are fictional SQLite fixtures.
$student = $sis->student();
$section = $sis->section(25, 0);
$app->make(\Domain\Registration\Service\RegistrationService::class)->register($student->id, $section->id);
$notice = $sis->sql->rows('sis_enrollment_updates')[0] ?? throw new RuntimeException('No accepted enrollment notice.');
$source = $sis->sql->rows('sis_history_events');
$endpoint = '/portal/api/enrollment-updates/';
$response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $endpoint,
)));
$page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $response->getStatusCode() !== 200 || count($page['items']) !== 1 ||
    isset($page['items'][0]['enrollment_id']) || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
    $response->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Enrollment notices lost their private minimized owner response.');
}
foreach (['read', 'delete'] as $action) {
    $path = $endpoint . $notice['id'] . '/' . $action . '/';
    $unsafe = $get->withMethod('POST')->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path))
        ->withParsedBody(['revision' => 1])->withHeader('Accept', 'application/json');
    if ($app->router->match($unsafe)->getStatusCode() !== 403) {
        throw new RuntimeException('Enrollment notice mutation bypassed framework CSRF.');
    }
    $result = $teachingPost($path, ['revision' => 1]);
    if (
        $result->getStatusCode() !== 200 || !str_contains($result->getHeaderLine('Cache-Control'), 'no-store') ||
        $result->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Enrollment notice mutation lost its private authorized response.');
    }
}
if ($sis->sql->rows('sis_enrollment_updates') !== [] || $sis->sql->rows('sis_history_events') !== $source) {
    throw new RuntimeException('Notice deletion retained the notice or changed protected registration history.');
}
