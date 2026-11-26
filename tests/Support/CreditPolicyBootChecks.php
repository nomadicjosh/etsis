<?php

declare(strict_types=1);

$section = $sis->section();
$coursePath = '/sis/ui/catalog/courses/' . $section->courseId->value . '/credits/';
$sectionPath = '/sis/ui/catalog/sections/' . $section->id->value . '/credits/';
$read = static function (string $path, int $status = 200) use ($app, $get): array {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $path,
    )));
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
        $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Credit settings lost their authorized private read contract.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$send = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if (
        !str_contains(
            $response->getHeaderLine('Cache-Control'),
            'no-store'
        ) || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Credit mutations lost their private transport contract.');
    }
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('Credit settings returned unexpected status ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$initial = $read($coursePath);
$body = ['revision' => $initial['revision'], 'credits_milli' => 3000,
    'minimum_milli' => 1000, 'maximum_milli' => 6000, 'increment_milli' => 500];
$saved = $send($coursePath, $body);
if ($saved['credit_policy']['increment_milli'] !== 500 || $saved['revision'] === $initial['revision']) {
    throw new RuntimeException('The credit policy was not saved atomically.');
}
$send($coursePath, $body, 409);
$send($coursePath, array_replace($body, ['revision' => $saved['revision'], 'credits_milli' => 2250]), 422);
$offering = $read($sectionPath);
$send($sectionPath, ['revision' => $offering['revision'], 'offering_credits_milli' => 2500], 422);
$chosen = $send($sectionPath, ['revision' => $offering['revision'], 'use_default' => false,
    'offering_credits_milli' => 2500]);
if ($chosen['offering_credits_milli'] !== 2500) {
    throw new RuntimeException('The section choice was not retained.');
}
$send($sectionPath, ['revision' => $chosen['revision'], 'use_default' => false,
    'offering_credits_milli' => 2250], 422);
$cleared = $send($sectionPath, ['revision' => $chosen['revision'], 'use_default' => true]);
if ($cleared['offering_credits_milli'] !== null) {
    throw new RuntimeException('The retained course default could not be restored.');
}
require_once __DIR__ . '/OfferingCreditFixture.php';
$variable = new \Tests\Support\OfferingCreditFixture($sis);
$variable->range();
$variable->bind();
$variablePath = '/sis/ui/catalog/sections/' . $variable->section->id->value . '/credits/';
$setup = $read($variablePath);
$chosen = $send($variablePath, ['revision' => $setup['revision'], 'use_default' => true,
    'student_selectable' => true]);
if ($chosen['student_selectable'] !== true) {
    throw new RuntimeException('The actual provider did not retain individual credit selection.');
}
$variable->select($variable->publish());
$student = $sis->student()->id;
$selection = ['section_id' => $variable->section->id->value, 'student_id' => $student->value, 'by_staff' => true];
$missing = $send('/sis/registrations/review/', $selection);
if ($missing['students'][0]['charges']['problems'] === []) {
    throw new RuntimeException('An unchosen variable-credit quantity was reviewable as ready.');
}
$send('/sis/registrations/review/', $selection + ['credit_choices' => [$student->value => 1.5]], 422);
$selection['credit_choices'] = [$student->value => 2500];
$review = $send('/sis/registrations/review/', $selection);
$result = $send('/sis/registrations/', $selection + array_intersect_key(
    $review,
    array_flip(['operation_id', 'revision', 'review_token'])
), 201);
if (
    $review['students'][0]['credits_milli'] !== 2500 ||
    (int) $sis->sql->one('sis_academic_credits', ['student_id' => $student->value])['attempted_milli'] !== 2500
) {
    throw new RuntimeException('The private registration route lost its exact credit choice.');
}
$ownerStudent = $student;
$foreignStudent = $sis->student()->id;
$ownerVariable = new \Tests\Support\OfferingCreditFixture($sis);
$ownerVariable->range();
$ownerVariable->bind();
$ownerVariable->choose(3000, true);
$ownerVariable->select($ownerVariable->publish());
$ownerSelection = ['student_id' => $ownerStudent->value, 'section_id' => $ownerVariable->section->id->value,
    'credits_milli' => 3500];
$sis->access->staff = false;
$ownerReview = $send('/portal/api/registration/review/', $ownerSelection);
$send('/portal/api/register/', $ownerSelection + array_intersect_key(
    $ownerReview,
    array_flip(['operation_id', 'revision', 'review_token'])
));
if (
    (int) $sis->sql->one('sis_academic_credits', ['student_id' => $ownerStudent->value,
    'course_id' => $ownerVariable->section->courseId->value])['attempted_milli'] !== 3500
) {
    throw new RuntimeException('The owner registration route lost its exact credit choice.');
}
$send(
    '/portal/api/registration/review/',
    array_replace($ownerSelection, ['student_id' => $foreignStudent->value]),
    403
);
$sis->access->staff = true;
$before = [$sis->sql->rows('sis_courses'), $sis->sql->rows('sis_sections'), $sis->sql->rows('sis_audit')];
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage', 'sis:student:read'];
$read($coursePath, 403);
$read($sectionPath, 403);
$send($coursePath, array_replace($body, ['revision' => $saved['revision']]), 403);
$send($sectionPath, ['revision' => $cleared['revision'], 'use_default' => true], 403);
if ($before !== [$sis->sql->rows('sis_courses'), $sis->sql->rows('sis_sections'), $sis->sql->rows('sis_audit')]) {
    throw new RuntimeException('An unauthorized credit edit changed data.');
}
echo "Credit-policy route checks passed.\n";
