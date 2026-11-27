<?php

declare(strict_types=1);

// Actual private providers/routes and CSRF. Fictional SQLite records only.
require_once __DIR__ . '/OfferingCreditFixture.php';
$offering = new \Tests\Support\OfferingCreditFixture($sis, fee: 10000);
$section = $offering->section->id;
$base = '/sis/sections/' . $section->value . '/auditing/';
$history = $sis->sql->rows('sis_history_events');
$current = $teachingPost($base . 'current/', []);
$data = json_decode((string) $current->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $current->getStatusCode() !== 200 || $data['policy'] !== null ||
    !str_contains($current->getHeaderLine('Cache-Control'), 'no-store') || $current->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Audit policy setup lost its private authorized response.');
}
$unsafe = $post->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base . 'save/'))
    ->withParsedBody(['allowed' => true, 'self_service' => true, 'fee_minor' => 2500, 'revision' => $data['revision']]);
if ($app->router->match($unsafe)->getStatusCode() !== 403) {
    throw new RuntimeException('Audit policy mutation bypassed framework CSRF.');
}
$saved = $teachingPost($base . 'save/', ['allowed' => true, 'self_service' => true,
    'fee_minor' => 2500, 'revision' => $data['revision']]);
if ($saved->getStatusCode() !== 200 || $sis->sql->rows('sis_history_events') !== $history) {
    throw new RuntimeException('Audit policy draft fabricated an academic publication.');
}
$offering->bind();
$offering->select($offering->publish());
$student = $sis->student();
$command = ['student_id' => $student->id->value, 'section_id' => $section->value,
    'by_staff' => true, 'basis' => 'audit'];
$review = $teachingPost('/sis/registrations/review/', $command);
$proof = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($review->getStatusCode() !== 200 || ($proof['basis'] ?? null) !== 'audit') {
    throw new RuntimeException('Audit registration review lost its explicit basis.');
}
$result = $teachingPost('/sis/registrations/', $command + array_intersect_key(
    $proof,
    array_flip(['operation_id', 'revision', 'review_token'])
));
$credit = $sis->sql->rows('sis_academic_credits')[0] ?? null;
if (
    $result->getStatusCode() !== 201 || ($credit['basis'] ?? null) !== 'audit' || $credit['attempted_milli'] !== 0 ||
    !str_contains($result->getHeaderLine('Cache-Control'), 'no-store') || $result->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Audit registration lost its protected private write contract.');
}
$sis->access->staff = false;
if (
    $teachingPost($base . 'current/', [])->getStatusCode() !== 403 ||
    $teachingPost($base . 'save/', ['allowed' => false, 'revision' => $data['revision']])->getStatusCode() !== 403
) {
    throw new RuntimeException('An ordinary account could edit institutional audit rules.');
}
