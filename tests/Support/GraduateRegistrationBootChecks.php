<?php

declare(strict_types=1);

// Actual providers, owner/advisor/registrar boundaries, CSRF and private responses; fictional SQLite only.
$graduatePost = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Graduate workflow response mismatch at " . $path . ": " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$f = $graduateFixture;
$base = "/sis/students/" . $f->student->value . "/graduate-courses/" . $f->target->section->id->value . "/";
$portalBase = str_replace("/sis/", "/portal/api/", $base);
$programBase = "/sis/sections/" . $f->target->section->id->value . "/eligible-programs/";
$policy = $graduatePost($programBase . "current/", []);
$program = $sis->workPursuits()->activeSelection($f->student)->programId;
$saved = $graduatePost($programBase . "save/", [
    "program_ids" => [$program->value],
    "revision" => $policy["revision"],
]);
$graduatePost($programBase . "save/", ["revision" => $saved["revision"]], 422);
$still = $graduatePost($programBase . "current/", []);
if ($still["program_ids"] !== [$program->value]) {
    throw new RuntimeException("An omitted program list cleared a saved policy.");
}
$graduatePost($programBase . "save/", ["program_ids" => [], "revision" => $saved["revision"]]);
$graduatePost("/sis/graduate-registration-policy/levels/", []);
$rules = $sis->graduatePolicies()->view();
$review = $graduatePost("/sis/graduate-registration-policy/review/", [
    "rules" => $rules["rules"],
    "version" => $rules["version"],
    "reason" => "Fictional policy HTTP publication.",
]);
$published = $graduatePost("/sis/graduate-registration-policy/publish/", $review);
if ($graduatePost("/sis/graduate-registration-policy/publish/", $review) !== $published) {
    throw new RuntimeException("Graduate policy publication lost its original receipt.");
}
$sis->access->staff = false;
$sis->access->actor = $f->advisorUser;
$sis->access->grants = [\Domain\Registration\Service\GraduateCourseApprovalService::ADVISOR];
$current = $graduatePost($portalBase . "current/", ["pursuit_id" => $f->pursuit->value]);
if (!$current["can_advisor"] || $current["can_registrar"]) {
    throw new RuntimeException("Advisor and registrar approval duties were mixed.");
}
$review = $graduatePost($portalBase . "review/", [
    "pursuit_id" => $f->pursuit->value,
    "action" => "advisor_approve",
    "version" => $current["version"],
    "reason" => "Fictional advisor approval.",
]);
$command = $review + ["pursuit_id" => $f->pursuit->value];
$accepted = $graduatePost($portalBase . "confirm/", $command);
if ($graduatePost($portalBase . "confirm/", $command) !== $accepted) {
    throw new RuntimeException("Graduate approval lost its original receipt.");
}
$graduatePost(
    $portalBase . "review/",
    [
        "pursuit_id" => $f->pursuit->value,
        "action" => "registrar_override",
        "version" => $accepted["version"],
        "reason" => "Fictional unauthorized override.",
    ],
    403,
);
$sis->access->actor = $f->registrar;
$sis->access->grants = [];
$owner = $graduatePost($portalBase . "current/", []);
if ($owner["can_advisor"] || $owner["can_registrar"] || isset($owner["gpa"]) || isset($owner["reason"])) {
    throw new RuntimeException("Owner status exposed protected approval details or staff controls.");
}
$sis->access->actor = $sis->user();
$graduatePost($portalBase . "current/", [], 403);
$sis->access->actor = $f->registrar;
$sis->access->staff = true;
$current = $graduatePost($base . "current/", []);
$review = $graduatePost($base . "review/", [
    "pursuit_id" => $f->pursuit->value,
    "action" => "registrar_override",
    "version" => $current["version"],
    "reason" => "Fictional registrar override.",
]);
$graduatePost($base . "confirm/", $review + ["pursuit_id" => $f->pursuit->value]);
$response = $registrationPost([
    "student_id" => $f->student->value,
    "section_id" => $f->target->section->id->value,
    "by_staff" => true,
]);
if ($response->getStatusCode() !== 201) {
    throw new RuntimeException(
        "Actual graduate registration did not complete after both approved decisions.",
    );
}
