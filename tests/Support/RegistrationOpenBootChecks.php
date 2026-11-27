<?php

declare(strict_types=1);

// Actual providers, routes, owner identity and framework CSRF over fictional SQLite only.
$base = '/portal/api/registration-open/';
$readRegistrationOpen = static function () use ($app, $get, $base): array {
    $uri = new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base);
    $response = $app->router->match($get->withUri($uri));
    if (
        $response->getStatusCode() !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Registration opening lost private routing.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$source = EventStoreFixture::historyDigest($sis->sql);
$items = $readRegistrationOpen()['items'];
if (count($items) !== 1 || $items[0]['id'] !== $sis->term->value || !$items[0]['unread']) {
    throw new RuntimeException('The published opening was unavailable through actual providers.');
}
$path = $base . $sis->term->value . '/';
if (
    $teachingPost($path . 'read/', ['revision' => 0])->getStatusCode() !== 422
    || $sis->sql->rows('sis_registration_open_reads') !== []
) {
    throw new RuntimeException('Invalid opening acknowledgement wrote personal state.');
}
if (
    $teachingPost($path . 'read/', ['revision' => 1])->getStatusCode() !== 200
    || $readRegistrationOpen()['items'] !== [] || count($sis->sql->rows('sis_registration_open_reads')) !== 1
) {
    throw new RuntimeException('Opening acknowledgement lost its revision or owner.');
}
if (
    $teachingPost($path . 'delete/', ['revision' => 1])->getStatusCode() !== 200
    || $sis->sql->rows('sis_registration_open_reads') !== [] || $readRegistrationOpen()['items'] !== []
) {
    throw new RuntimeException('Opening deletion left read data or recreated the same notice.');
}
$sis->access->actor = $sis->user();
$sis->access->staff = true;
if (
    $readRegistrationOpen()['items'] !== []
    || $teachingPost($path . 'read/', ['revision' => 1])->getStatusCode() !== 404
) {
    throw new RuntimeException('Office privileges bypassed the owner/student audience.');
}
if ($source !== EventStoreFixture::historyDigest($sis->sql)) {
    throw new RuntimeException('Reading notifications changed authoritative facts.');
}
