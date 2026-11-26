<?php

declare(strict_types=1);

// Actual application providers, private transport and CSRF; all records are fictional SQLite fixtures.
$staff = $sis->access->actor;
$student = $sis->student($sis->user());
$pursuit = new \Domain\Shared\ValueObject\EntityId($sis->sql->rows('sis_student_programs', [
    'student_id' => $student->id->value,
], 1)[0]['id']);
$service = $app->make(\Domain\Student\Service\StudentStandingService::class);
$definition = $service->saveDefinition(null, 'GOOD', 'Fictional good standing', true, null);
$period = ['id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
    'definition_id' => $definition['id'], 'code' => 'GOOD', 'title' => 'Fictional good standing',
    'starts_on' => '2026-08-01', 'ends_on' => null, 'policy_reference' => 'Fictional private policy'];
$review = $service->review(
    $student->id,
    $pursuit,
    [$period],
    $service->view($student->id, $pursuit)['revision'],
    'Fictional private registrar explanation.'
);
$receipt = $service->commit(
    $student->id,
    $pursuit,
    new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
    $review['rows'],
    $review['revision'],
    $review['reason'],
    $review['review_token']
);
$notice = $sis->sql->rows('sis_standing_updates')[0] ?? throw new RuntimeException('No accepted standing notice.');
$source = [$sis->sql->rows('sis_history_events'), $sis->sql->rows('sis_student_standing')];
$owner = $sis->admissions->person($student->personId)->ownerUserId;
$privateGet = static function (string $path, int $status = 200) use ($app, $get): array {
    $uri = new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path);
    $response = $app->router->match($get->withUri($uri));
    if (
        $response->getStatusCode() !== $status
        || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Standing updates lost their private authorized transport.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$id = '/portal/api/standing-updates/' . $notice['id'] . '/';
$sis->access->actor = $owner;
$sis->access->staff = false;
$list = $privateGet('/portal/api/standing-updates/');
if (count($list['items']) !== 1 || isset($list['items'][0]['pursuit_id'])) {
    throw new RuntimeException('Standing notice listing did not minimize its references.');
}
$detail = $privateGet($id);
if (
    ($detail['items'][0]['title'] ?? null) !== 'Fictional good standing'
    || str_contains(json_encode($detail), 'Fictional private')
) {
    throw new RuntimeException('Standing notice detail lost its retained owner-only presentation.');
}
foreach (['read', 'delete'] as $action) {
    $request = $get->withMethod('POST')->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $id . $action . '/'
    ))->withParsedBody(['revision' => 1])->withHeader('Accept', 'application/json');
    if ($app->router->match($request)->getStatusCode() !== 403) {
        throw new RuntimeException('Standing notice mutations accepted missing CSRF.');
    }
    $response = $teachingPost($id . $action . '/', ['revision' => 1]);
    if ($response->getStatusCode() !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
        throw new RuntimeException('The authorized standing notice mutation failed.');
    }
}
if (
    $sis->sql->rows('sis_standing_updates') !== []
    || $source !== [$sis->sql->rows('sis_history_events'), $sis->sql->rows('sis_student_standing')]
) {
    throw new RuntimeException('Deleting standing updates changed academic facts or retained the notice.');
}
$sis->access->actor = $staff;
$sis->access->staff = true;
$privateGet($id, 403);
$repeated = $service->commit(
    $student->id,
    $pursuit,
    new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
    $review['rows'],
    $review['revision'],
    $review['reason'],
    $review['review_token']
);
if ($receipt !== $repeated || $sis->sql->rows('sis_standing_updates') !== []) {
    throw new RuntimeException('An accepted retry recreated a disposable standing update.');
}
