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
