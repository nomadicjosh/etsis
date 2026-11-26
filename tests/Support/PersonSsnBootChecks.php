<?php

declare(strict_types=1);

require_once __DIR__ . '/PersonSsnFixture.php';
$f = new \Tests\Support\PersonSsnFixture();
$httpSis = $f->sis;
require __DIR__ . '/SisHttpFixture.php';
$base = '/sis/people/' . $f->person->id->value . '/ssn/';
$call = static function (string $suffix, array $body, int $status = 200) use ($teachingPost, $base): array {
    $response = $teachingPost($base . $suffix, $body);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('SSN mutation returned ' . $response->getStatusCode());
    }
    $json = (string) $response->getBody();
    if (str_contains($json, '000001234')) {
        throw new RuntimeException('A private SSN response exposed full input.');
    }
    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
};
$read = static function (int $status = 200) use ($app, $get, $base): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base))
    );
    if (
        $response->getStatusCode() !== $status
        || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException(
            'SSN read lost its access or private transport boundary: HTTP ' . $response->getStatusCode()
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
if ($read()['version'] !== 0) {
    throw new RuntimeException('A person without SSN history acquired a fabricated number.');
}
$command = ['ssn' => '000001234', 'ssn_confirmation' => '000-00-1234', 'version' => 0,
    'reason' => 'Fictional verified HTTP correction.'];
$call('review/', ['version' => 0, 'reason' => $command['reason']], 422);
$call('review/', array_replace($command, ['ssn_confirmation' => '000005678']), 422);
$before = $f->facts();
$review = $call('review/', $command);
if ($review['after'] !== '***-**-1234' || $f->facts() !== $before) {
    throw new RuntimeException('SSN review wrote facts or disclosed the proposed number.');
}
$confirmation = $command + ['operation_id' => $review['operation_id'], 'review_token' => $review['review_token']];
$f->grant(\Domain\Person\Service\PersonSsnService::MANAGE, false);
$before = $f->facts();
$call('confirm/', $confirmation, 403);
if ($f->facts() !== $before || $read()['can_edit'] !== false) {
    throw new RuntimeException('Revoked SSN correction duty did not fail closed.');
}
$f->grant(\Domain\Person\Service\PersonSsnService::MANAGE, true);
$receipt = $call('confirm/', $confirmation);
if ($call('confirm/', $confirmation) !== $receipt || $read()['masked'] !== '***-**-1234') {
    throw new RuntimeException('SSN confirmation lost its mask or original receipt.');
}
$history = $call('history/', []);
if (count($history['items']) !== 1 || array_key_exists('number', $history['items'][0])) {
    throw new RuntimeException('SSN history did not preserve the curated boundary.');
}
$clear = ['ssn' => null, 'ssn_confirmation' => null, 'version' => 1, 'reason' => 'Fictional explicit removal.'];
$clearReview = $call('review/', $clear);
$call('confirm/', $clear + [
    'operation_id' => $clearReview['operation_id'], 'review_token' => $clearReview['review_token'],
]);
if ($read()['masked'] !== null) {
    throw new RuntimeException('Clearing the saved SSN did not clear its current mask.');
}
$sis->access->actor = $f->person->ownerUserId;
$sis->access->staff = false;
$read(403);
