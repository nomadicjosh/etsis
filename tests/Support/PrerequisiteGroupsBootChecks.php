<?php

declare(strict_types=1);

// Real providers, routing and CSRF; only fictional SQLite courses and publication history.
$target = $sis->section(20, 0);
$first = $sis->section(20, 0);
$alternative = $sis->section(20, 0);
$additional = $sis->section(20, 0);
$corequisite = $sis->section(20, 0);
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
$groups = [[$first->courseId->value, $alternative->courseId->value, $additional->courseId->value],
    [$alternative->courseId->value]];
$input = ['completion_windows' => null, 'requirement_credit_origins' => null,
    'credit_origin' => null, 'minimum_requirements' => 1,
    'groups' => $groups, 'minimums' => [2, 1],
    'grade_points_milli' => [2675, null], 'corequisites' => [$corequisite->courseId->value],
    'revision' => $draft['revision']];
foreach ([0, -1, 3, 1.5, [], true] as $invalid) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace(
            $input,
            ['minimum_requirements' => $invalid]
        ))->getStatusCode() !== 422
    ) {
        throw new RuntimeException('An impossible overall prerequisite count bypassed the private route.');
    }
}
foreach ([null, [], [0, 1], [4, 1], ['2', 1]] as $invalid) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace($input, ['minimums' => $invalid]))
        ->getStatusCode() !== 422
    ) {
        throw new RuntimeException('Malformed course counts bypassed the actual prerequisite route.');
    }
}
foreach ([null, [], [-1, null], [10001, null], ['2675', null], [2.5, null]] as $invalid) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace($input, ['grade_points_milli' => $invalid]))
        ->getStatusCode() !== 422
    ) {
        throw new RuntimeException('Malformed grade minima bypassed the actual prerequisite route.');
    }
}
foreach (['invalid', [], ['transfer'], ['either', false], ['bad', null], ['row' => 'transfer']] as $invalid) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace(
            $input,
            ['completion_windows' => null, 'requirement_credit_origins' => $invalid]
        ))->getStatusCode() !== 422
    ) {
        throw new RuntimeException('Malformed per-requirement sources bypassed the actual private route.');
    }
}
$missingOrigins = $input;
unset($missingOrigins['requirement_credit_origins']);
if ($teachingPost($base . 'prerequisite-groups/', $missingOrigins)->getStatusCode() !== 422) {
    throw new RuntimeException('The current prerequisite API accepted an omitted source-policy choice.');
}
$input['requirement_credit_origins'] = ['institutional', 'either'];
foreach (
    [null, 'invalid', [123], [$target->courseId->value],
    [$corequisite->courseId->value, $corequisite->courseId->value]] as $invalid
) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace($input, ['corequisites' => $invalid]))
        ->getStatusCode() !== 422
    ) {
        throw new RuntimeException('Malformed co-requisites bypassed catalog validation.');
    }
}
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
$expected = new \Domain\Catalog\ValueObject\PrerequisiteGroups($groups, [2, 1])->groups;
if (
    $reviewResponse->getStatusCode() !== 200 || ($review['definition']['prerequisite_groups'] ?? null) !== $expected ||
    ($review['definition']['prerequisite_group_minimums'] ?? null) !== [2, 1] ||
    ($review['definition']['prerequisite_requirement_minimum'] ?? null) !== 1 ||
    ($review['definition']['prerequisite_grade_points_milli'] ?? null) !== [2675, null] ||
    ($review['definition']['prerequisite_requirement_credit_origins'] ?? null) !== ['institutional', 'either'] ||
    ($review['definition']['corequisites'] ?? null) !== [$corequisite->courseId->value]
) {
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
$currentNested = json_decode((string) $read()->getBody(), true, flags: JSON_THROW_ON_ERROR);
$nestedRule = ['minimum' => 2, 'children' => [0, ['minimum' => 1, 'children' => [1, 2]]]];
$nestedInput = array_replace($input, ['revision' => $currentNested['revision'], 'minimum_requirements' => null,
    'requirement_credit_origins' => ['institutional', 'either', null],
    'rule' => $nestedRule, 'groups' => [...$groups, [$additional->courseId->value]], 'minimums' => [2, 1, 1],
    'grade_points_milli' => [2675, null, null]]);
foreach (
    [false, 'invalid', [], ['minimum' => 1, 'children' => [0, 1]],
    ['minimum' => 1, 'children' => [0, 0, 1, 2]]] as $invalidRule
) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace($nestedInput, ['rule' => $invalidRule]))
        ->getStatusCode() !== 422
    ) {
        throw new RuntimeException('Malformed nested prerequisite rules bypassed private input validation.');
    }
}
$nestedSaved = $teachingPost($base . 'prerequisite-groups/', $nestedInput);
$nestedData = json_decode((string) $nestedSaved->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $nestedSaved->getStatusCode() !== 200 || ($nestedData['rule'] ?? null) !== $nestedRule ||
    $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Saving a nested draft lost its rule or invented a historical decision.');
}
$nestedReview = $teachingPost($base . 'publication/review/', ['reason' => 'Fictional nested route approval.']);
$nestedProof = json_decode((string) $nestedReview->getBody(), true, flags: JSON_THROW_ON_ERROR);
$nestedConfirmed = $teachingPost($base . 'publication/commit/', ['review_token' => $nestedProof['review_token']]);
if (
    $nestedReview->getStatusCode() !== 200 ||
    ($nestedProof['definition']['prerequisite_rule'] ?? null) !== $nestedRule ||
    $nestedConfirmed->getStatusCode() !== 200 ||
    (string) $teachingPost($base . 'publication/commit/', ['review_token' => $review['review_token']])->getBody() !==
        (string) $confirmed->getBody()
) {
    throw new RuntimeException('Nested publication or the earlier original receipt changed.');
}
$policyRead = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/registration-policy/'
)));
$policy = json_decode((string) $policyRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
$policyInput = ['mode' => $policy['mode'], 'revision' => $policy['revision'],
    'prior_completion' => false, 'drop_mode' => 'warn', 'coreq_revision' => $policy['corequisites']['revision']];
$policySaved = $teachingPost('/sis/registration-policy/', $policyInput);
$policyResult = json_decode((string) $policySaved->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $policySaved->getStatusCode() !== 200 || ($policyResult['corequisites']['prior_completion'] ?? null) !== false ||
    ($policyResult['corequisites']['drop_mode'] ?? null) !== 'warn'
) {
    throw new RuntimeException('Registration policy did not resolve its co-requisite settings.');
}
$policyInput['revision'] = $policyResult['revision'];
$policyInput['mode'] = 'warn';
if (
    $teachingPost('/sis/registration-policy/', $policyInput)->getStatusCode() !== 409 ||
    (new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository($sis->sql))->policy()['mode'] !==
        $policy['mode']
) {
    throw new RuntimeException('A stale co-requisite policy left a partial timetable policy write.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage'];
if (
    $teachingPost('/sis/registration-policy/', $policyInput)->getStatusCode() !== 403 ||
    $read()->getStatusCode() !== 403 ||
    $teachingPost($base . 'prerequisite-groups/', $input)->getStatusCode() !== 403 ||
    $teachingPost('/sis/prerequisite-labels/', ['ids' => [$first->courseId->value]])->getStatusCode() !== 403
) {
    throw new RuntimeException('Registration authority bypassed prerequisite catalog management.');
}


// Explicit source selection uses the same draft, publication and private HTTP boundaries.
$sis->access->staff = true;
foreach (['institutional', 'transfer', 'either', null] as $origin) {
    $originDraft = json_decode((string) $read()->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $originInput = array_replace($input, ['revision' => $originDraft['revision'], 'credit_origin' => $origin]);
    foreach (['unknown', '', 4, true, []] as $invalidOrigin) {
        if (
            $teachingPost($base . 'prerequisite-groups/', array_replace($originInput, [
            'credit_origin' => $invalidOrigin,
            ]))->getStatusCode() !== 422
        ) {
            throw new RuntimeException('Malformed prerequisite coursework origin bypassed validation.');
        }
    }
    $missingOrigin = $originInput;
    unset($missingOrigin['credit_origin']);
    if ($teachingPost($base . 'prerequisite-groups/', $missingOrigin)->getStatusCode() !== 422) {
        throw new RuntimeException('Prerequisite mutations silently omitted their coursework-origin choice.');
    }
    $originSaved = $teachingPost($base . 'prerequisite-groups/', $originInput);
    $originResult = json_decode((string) $originSaved->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($originSaved->getStatusCode() !== 200 || ($originResult['credit_origin'] ?? null) !== $origin) {
        throw new RuntimeException('The prerequisite origin choice did not persist through actual providers.');
    }
    $originReview = $teachingPost($base . 'publication/review/', ['reason' => 'Fictional origin publication.']);
    $originPlan = json_decode((string) $originReview->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $originReview->getStatusCode() !== 200
        || ($originPlan['definition']['prerequisite_credit_origin'] ?? null) !== $origin
    ) {
        throw new RuntimeException('The course publication review lost its prerequisite origin.');
    }
}

// Completion policy is explicit, revisioned and validated through the actual private save route.
$sis->access->staff = true;
$windowDraft = json_decode((string) $read()->getBody(), true, flags: JSON_THROW_ON_ERROR);
$window = ['from' => '2020-01-01', 'through' => '2025-12-31'];
$windowInput = ['revision' => $windowDraft['revision'], 'groups' => [[$first->courseId->value]],
    'minimums' => [1], 'grade_points_milli' => [null], 'corequisites' => [], 'minimum_requirements' => null,
    'credit_origin' => null, 'requirement_credit_origins' => null, 'rule' => null, 'completion_windows' => [$window]];
$missingWindow = $windowInput;
unset($missingWindow['completion_windows']);
if ($teachingPost($base . 'prerequisite-groups/', $missingWindow)->getStatusCode() !== 422) {
    throw new RuntimeException('The prerequisite API accepted an omitted completion policy.');
}
foreach (
    [false, [], [$window, null], [['from' => '2025-02-30', 'through' => null]],
    [['from' => '2026-01-01', 'through' => '2025-01-01']]] as $invalid
) {
    if (
        $teachingPost($base . 'prerequisite-groups/', array_replace(
            $windowInput,
            ['completion_windows' => $invalid]
        ))->getStatusCode() !== 422
    ) {
        throw new RuntimeException('Malformed completion windows bypassed private input validation.');
    }
}
$windowSaved = $teachingPost($base . 'prerequisite-groups/', $windowInput);
$windowResult = json_decode((string) $windowSaved->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($windowSaved->getStatusCode() !== 200 || $windowResult['completion_windows'] !== [$window]) {
    throw new RuntimeException('The actual prerequisite API lost completion boundaries.');
}
