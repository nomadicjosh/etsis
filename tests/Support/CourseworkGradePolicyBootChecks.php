<?php

declare(strict_types=1);

use Domain\Gradebook\ValueObject\CourseworkGradePolicy;

$section = $sis->section(faculty: $sis->access->actor);
$path = "/sis/sections/" . $section->id->value . "/gradebook/calculation/";
$readPolicySetup = static function () use ($app, $get, $path): array {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($path)));
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Gradebook policy setup failed its private read contract.");
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$sis->access->staff = false;
$sis->access->grants = [];
$setup = $readPolicySetup();
$body = ["mode" => "points", "weights" => [], "revision" => $setup["revision"]];
if (
    $setup["can_manage_grade_policy"] ||
    $setup["grade_policy"] !== CourseworkGradePolicy::DEFAULT ||
    $teachingPost($path, $body)->getStatusCode() !== 422
) {
    throw new RuntimeException("Gradebook policy duty or explicit policy input failed.");
}
$policy = ["rounding" => "half_up", "decimal_places" => 0, "cap_at_hundred" => true];
$body["grade_policy"] = $policy;
$before = $sis->sql->rows("sis_history_events");
if ($teachingPost($path, $body)->getStatusCode() !== 403) {
    throw new RuntimeException("Assigned faculty gained coursework policy authority.");
}
$sis->access->grants = ["sis:gradebook:calculation-policy"];
$saved = $teachingPost($path, $body);
$current = json_decode((string) $saved->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $saved->getStatusCode() !== 200 ||
    $current["grade_policy"] !== $policy ||
    !$current["can_manage_grade_policy"] ||
    $before !== $sis->sql->rows("sis_history_events") ||
    $teachingPost($path, $body)->getStatusCode() !== 409
) {
    throw new RuntimeException(
        "Explicit policy save failed concurrency or wrote academic history.",
    );
}
$sis->access->grants = [];
$body["revision"] = $current["revision"];
if (
    $teachingPost($path, $body)->getStatusCode() !== 200 ||
    $readPolicySetup()["can_manage_grade_policy"]
) {
    throw new RuntimeException("Faculty could not preserve policy while saving working setup.");
}
$workingAssignment = new \Domain\Gradebook\Assignment(
    \Domain\Shared\ValueObject\EntityId::generate(),
    $section->id,
    'Fictional category exam',
    10000,
    new \DateTimeImmutable('2026-09-01'),
);
$sis->gradebook()->addAssignment($workingAssignment);
$body['mode'] = 'category';
$body['categories'] = [['title' => 'Fictional exams', 'weight_hundredths' => 10000,
    'assignments' => [$workingAssignment->id->value], 'drop_lowest' => 1,
    'protected_assignments' => [$workingAssignment->id->value], 'cap_at_hundred' => true,
    'bonus_only_cap_hundredths' => 0, 'regular_bonus_cap_hundredths' => 555]];
$body['revision'] = $readPolicySetup()['revision'];
$categoryResponse = $teachingPost($path, $body);
$categorySaved = json_decode((string) $categoryResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($categoryResponse->getStatusCode() !== 200 || $categorySaved['categories'] !== $body['categories']) {
    throw new RuntimeException('Faculty category rules failed the ordinary setup boundary.');
}
$invalidCategories = $body;
$invalidCategories['revision'] = $categorySaved['revision'];
$invalidCategories['categories'][0]['cap_at_hundred'] = 'true';
if (
    $teachingPost($path, $invalidCategories)->getStatusCode() !== 422
    || $readPolicySetup()['categories'] !== $body['categories']
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Malformed category rules changed setup or academic history.');
}
foreach (['bonus_only_cap_hundredths', 'regular_bonus_cap_hundredths'] as $field) {
    foreach ([null, '500', -1, 10001, 1.5] as $invalidLimit) {
        $invalidCategories = $body;
        $invalidCategories['revision'] = $categorySaved['revision'];
        $invalidCategories['categories'][0][$field] = $invalidLimit;
        if (
            $teachingPost($path, $invalidCategories)->getStatusCode() !== 422 ||
            $readPolicySetup()['categories'] !== $body['categories'] ||
            $before !== $sis->sql->rows('sis_history_events')
        ) {
            throw new RuntimeException('An invalid bonus contribution limit escaped the private setup boundary.');
        }
    }
}
$sis->access->actor = $sis->user();
$sis->access->grants = ["sis:gradebook:calculation-policy"];
if ($teachingPost($path, $body)->getStatusCode() !== 403) {
    throw new RuntimeException("Policy duty exposed an unrelated faculty section.");
}

$sis->access->staff = true;
$policyPath = "/sis/grade-comparison-policy/";
$readComparison = static function (int $status = 200) use ($app, $get, $policyPath): array {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($policyPath)));
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Institutional policy violated its status/private-response contract.",
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$sis->access->staff = false;
foreach ([["sis:gradebook:manage"], ["sis:gradebook:calculation-policy:publish"]] as $grants) {
    $sis->access->grants = $grants;
    $readComparison(403);
}
$sis->access->grants = ["sis:gradebook:manage", "sis:gradebook:calculation-policy:publish"];
$original = $readComparison();
$reviewResponse = $teachingPost($policyPath . "review/", [
    "rules" => $policy,
    "version" => $original["version"],
    "reason" => "Fictional institutional comparison approval.",
]);
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($reviewResponse->getStatusCode() !== 200) {
    throw new RuntimeException("The institutional comparison review route failed.");
}
$published = $teachingPost($policyPath . "publish/", $review);
$receipt = json_decode((string) $published->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($published->getStatusCode() !== 200 || $readComparison()["rules"] != $policy) {
    throw new RuntimeException("The institutional comparison publication route failed.");
}
$sis->access->staff = true;
$newSection = $sis->section(faculty: $sis->access->actor);
$newPath = "/sis/sections/" . $newSection->id->value . "/gradebook/calculation/";
$inherited = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($newPath)));
$inherited = json_decode((string) $inherited->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $inherited["grade_policy_scope"] !== "institution" ||
    $inherited["grade_policy"] != $policy ||
    $inherited["grade_policy_reference"]["publication_id"] !== $receipt["publication_id"]
) {
    throw new RuntimeException("Real provider section inheritance lost its protected publication.");
}
$sis->clock->time = $sis->clock->time->modify("+21 minutes");
$before = $sis->sql->rows("sis_history_events");
$again = $teachingPost($policyPath . "publish/", $review);
if (
    json_decode((string) $again->getBody(), true, flags: JSON_THROW_ON_ERROR) !== $receipt ||
    $before !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException("Institutional publication retry changed its original facts.");
}
