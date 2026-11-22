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
$sis->access->actor = $sis->user();
$sis->access->grants = ["sis:gradebook:calculation-policy"];
if ($teachingPost($path, $body)->getStatusCode() !== 403) {
    throw new RuntimeException("Policy duty exposed an unrelated faculty section.");
}
