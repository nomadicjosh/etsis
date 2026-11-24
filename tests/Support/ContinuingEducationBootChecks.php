<?php

declare(strict_types=1);

$section = $sis->section();
$coursePath = '/sis/ui/catalog/courses/' . $section->courseId->value . '/ceus/';
$sectionPath = '/sis/ui/catalog/sections/' . $section->id->value . '/ceus/';
$read = static function (string $path, int $status = 200) use ($app, $get): array {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $path,
    )));
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
        $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('CEU settings lost their authorized private read contract.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$send = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('CEU settings returned unexpected status ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$initial = $read($coursePath);
$body = ['revision' => $initial['revision'], 'mode' => 'fixed', 'ceu_milli' => 1234];
$saved = $send($coursePath, $body);
if ($saved['ceu_milli'] !== 1234 || $saved['revision'] === $initial['revision']) {
    throw new RuntimeException('The CEU declaration was not saved atomically.');
}
$send($coursePath, $body, 409);
$send($coursePath, array_replace($body, ['revision' => $saved['revision'], 'ceu_milli' => -1]), 422);
$send($coursePath, array_replace($body, ['revision' => $saved['revision'], 'mode' => 'inherit']), 422);
$send($coursePath, array_replace($body, ['revision' => $saved['revision'], 'mode' => 'automatic']), 422);
$send($coursePath, array_replace($body, ['revision' => $saved['revision'], 'mode' => 'none']), 422);
$offering = $read($sectionPath);
$send($sectionPath, ['revision' => $offering['revision'], 'ceu_milli' => 2500], 422);
$chosen = $send($sectionPath, ['revision' => $offering['revision'], 'mode' => 'fixed', 'ceu_milli' => 2500]);
if ($chosen['ceu_milli'] !== 2500) {
    throw new RuntimeException('The offered CEUs were not retained.');
}
$cleared = $send($sectionPath, ['revision' => $chosen['revision'], 'mode' => 'none']);
if ($cleared['mode'] !== 'none' || $cleared['ceu_milli'] !== null) {
    throw new RuntimeException('An explicit no-CEU choice was not retained.');
}
$cleared = $send($sectionPath, ['revision' => $cleared['revision'], 'mode' => 'inherit']);
if ($cleared['mode'] !== 'inherit' || $cleared['course_continuing_education'] !== ['ceu_milli' => 1234]) {
    throw new RuntimeException('The course declaration could not be restored.');
}
$before = [$sis->sql->rows('sis_courses'), $sis->sql->rows('sis_sections'), $sis->sql->rows('sis_audit')];
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage', 'sis:student:read'];
$read($coursePath, 403);
$read($sectionPath, 403);
$send($coursePath, array_replace($body, ['revision' => $saved['revision']]), 403);
$send($sectionPath, ['revision' => $cleared['revision'], 'mode' => 'inherit'], 403);
if ($before !== [$sis->sql->rows('sis_courses'), $sis->sql->rows('sis_sections'), $sis->sql->rows('sis_audit')]) {
    throw new RuntimeException('An unauthorized CEU edit changed data.');
}
echo "CEU route checks passed.\n";
