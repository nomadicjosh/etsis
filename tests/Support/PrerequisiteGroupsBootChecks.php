<?php

declare(strict_types=1);

// Real providers, routing and CSRF; only fictional SQLite courses and publication history.
$target = $sis->section(20, 0);
$first = $sis->section(20, 0);
$alternative = $sis->section(20, 0);
$additional = $sis->section(20, 0);
$base = '/sis/courses/' . $target->courseId->value . '/';
$read = static function () use ($app, $get, $base): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $base . 'prerequisite-groups/'
    )));
    if (
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
        $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Prerequisite draft reads lost their private boundary.');
    }
    return $response;
};
$response = $read();
$draft = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || ($draft['groups'] ?? null) !== []) {
    throw new RuntimeException('The prerequisite editor did not resolve through actual providers.');
}
$groups = [[$first->courseId->value, $alternative->courseId->value], [$additional->courseId->value]];
$input = ['groups' => $groups, 'revision' => $draft['revision']];
if (
    $teachingPost($base . 'prerequisite-groups/', ['revision' => $draft['revision']])->getStatusCode() !== 422 ||
    $teachingPost($base . 'prerequisite-groups/', $input)->getStatusCode() !== 200 ||
    $teachingPost($base . 'prerequisite-groups/', $input)->getStatusCode() !== 409
) {
    throw new RuntimeException('Prerequisite saves bypassed explicit choices or expected revisions.');
}
if ($teachingPost('/sis/prerequisite-labels/', ['ids' => [123]])->getStatusCode() !== 422) {
    throw new RuntimeException('A malformed course label identity was not rejected as invalid input.');
}
$labels = $teachingPost('/sis/prerequisite-labels/', [
    'ids' => [$first->courseId->value, $alternative->courseId->value],
]);
$data = json_decode((string) $labels->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($labels->getStatusCode() !== 200 || count($data['items'] ?? []) !== 2) {
    throw new RuntimeException('Course publication labels lost their bounded catalog route.');
}
$reviewResponse = $teachingPost($base . 'publication/review/', ['reason' => 'Fictional prerequisite approval']);
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$expected = new \Domain\Catalog\ValueObject\PrerequisiteGroups($groups)->groups;
if ($reviewResponse->getStatusCode() !== 200 || ($review['definition']['prerequisite_groups'] ?? null) !== $expected) {
    throw new RuntimeException('Course publication omitted the reviewed prerequisite choices.');
}
$confirmed = $teachingPost($base . 'publication/commit/', ['review_token' => $review['review_token']]);
$retry = $teachingPost($base . 'publication/commit/', ['review_token' => $review['review_token']]);
if ($confirmed->getStatusCode() !== 200 || (string) $confirmed->getBody() !== (string) $retry->getBody()) {
    throw new RuntimeException('Prerequisite publication failed its protected history or original-result retry.');
}
$before = $sis->sql->rows('sis_history_events');
$read();
$teachingPost('/sis/prerequisite-labels/', ['ids' => [$first->courseId->value]]);
if ($before !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('Reading prerequisite drafts or labels changed history.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage'];
if (
    $read()->getStatusCode() !== 403 ||
    $teachingPost($base . 'prerequisite-groups/', $input)->getStatusCode() !== 403 ||
    $teachingPost('/sis/prerequisite-labels/', ['ids' => [$first->courseId->value]])->getStatusCode() !== 403
) {
    throw new RuntimeException('Registration authority bypassed prerequisite catalog management.');
}
