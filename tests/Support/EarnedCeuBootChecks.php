<?php

declare(strict_types=1);

$base = '/sis/students/' . $earnedFixture->student->value . '/sections/' .
$earnedFixture->offering->section->id->value . '/enrollments/' . $earnedFixture->enrollment->value . '/earned-ceus/';
$send = static function (string $suffix, array $data = [], int $status = 200) use ($teachingPost, $base): array {
    $response = $teachingPost($base . $suffix . '/', $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('Earned CEU route returned unexpected status ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$view = $send('view');
$body = ['expected_version' => $view['expected_version'], 'expected_position' => $view['expected_position'],
    'earned_milli' => 1250, 'effective_on' => '2026-08-01', 'reason' => 'Fictional institutional CEU evidence.',
    'acknowledged' => true];
if ($earnedFixture->withdrawn) {
    $body['completed_on'] = '2026-08-01';
    $send('review', array_diff_key($body, ['completed_on' => true]), 422);
}
$send('review', array_diff_key($body, ['earned_milli' => true]), 422);
$send('review', array_replace($body, ['earned_milli' => 1251]), 422);
$review = $send('review', $body);
$confirmation = ['command' => $review['command'], 'review_token' => $review['review_token']];
$receipt = $send('confirm', $confirmation);
if ($earnedFixture->withdrawn) {
    $before = $sis->sql->one('sis_academic_credits', ['id' => $earnedFixture->credit->value]);
    if ($before['status'] !== 'W' || (int) $before['completed_milli'] !== 0 || $send('view')['completed_on'] !== null) {
        throw new RuntimeException('A withdrawn CEU decision changed academic completion.');
    }
    $sis->access->staff = false;
    $sis->access->grants = ['sis:student:read', 'sis:academic:ceus:amend'];
    $send('confirm', $confirmation, 403);
    $send('history', [], 403);
    $send('review', $body, 403);
    $sis->access->staff = true;
}
if ($send('confirm', $confirmation) !== $receipt || $send('view')['earned_ceus']['earned_milli'] !== 1250) {
    throw new RuntimeException('Earned CEU confirmation or original retry failed.');
}
$send('history');
$otherStudent = $sis->student();
$originalActor = $sis->access->actor;
$person = $sis->admissions->person($sis->admissions->student($earnedFixture->student)->personId);
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $person->ownerUserId;
$read = static function (string $path, int $status = 200) use ($app, $get): array {
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $path
    ))->withQueryParams($query));
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('CEU owner read lost its private authorization contract.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$ownerBase = '/portal/api/students/' . $earnedFixture->student->value . '/transcript/';
$academic = $read($ownerBase);
$continuing = $read($ownerBase . '?purpose=continuing_education');
if (
    array_key_exists('earned_ceus', $academic['items'][0]) ||
    $continuing['items'][0]['earned_ceus']['earned_milli'] !== 1250 ||
    array_key_exists('completed_milli', $continuing['items'][0]) ||
    str_contains(json_encode($continuing, JSON_THROW_ON_ERROR), 'Fictional institutional CEU evidence')
) {
    throw new RuntimeException('Academic/CEU transcript scope or CEU privacy was lost.');
}
$read('/portal/api/students/' . $otherStudent->id->value . '/transcript/?purpose=continuing_education', 403);
$sis->access->actor = $originalActor;
$sis->access->staff = true;
$before = [$sis->sql->rows('sis_history_events'), $sis->sql->rows('sis_academic_credits'),
    $sis->sql->rows('sis_academic_credit_streams'), $sis->sql->rows('sis_audit')];
$sis->access->staff = false;
$sis->access->grants = ['sis:student:read'];
if ($send('view')['can_manage']) {
    throw new RuntimeException('Student-read permission granted CEU authority.');
}
$send('review', $body, 403);
$send('confirm', $confirmation, 403);
$send('history', [], 403);
if (
    $before !== [$sis->sql->rows('sis_history_events'), $sis->sql->rows('sis_academic_credits'),
    $sis->sql->rows('sis_academic_credit_streams'), $sis->sql->rows('sis_audit')]
) {
    throw new RuntimeException('An unauthorized earned CEU action changed records.');
}
$sis->access->grants = ['sis:academic:ceus:amend'];
$send('view', [], 403);
$send('confirm', $confirmation, 403);
echo "Earned CEU route checks passed.\n";
