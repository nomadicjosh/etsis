<?php

declare(strict_types=1);

// Fictional data, actual provider bindings, privacy middleware and framework CSRF.
$student = $sis->student()->id;
$pursuit = $sis->workPursuits()->activePursuit($student);
$section = $sis->section();
$required = $sis->section();
$sis->registration->addPrerequisite($section->courseId, $required->courseId);
$version = $sis->publishCourse($section->courseId);
$bindings = $app->make(\Domain\Registration\Service\SectionCourseBindingService::class);
$binding = $bindings->review($section->id, $version, "Fictional course selection.");
$bindings->commit($section->id, $binding["review_token"]);
$base = "/sis/students/" . $student->value . "/programs/" . $pursuit->value . "/prerequisite-exceptions/";
$path = $base . $section->id->value . "/";
$exceptionPost = static function (string $path, array $body) use ($teachingPost): array {
    $response = $teachingPost($path, $body);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Private prerequisite exception route failed: " . $path . " status " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$exceptionPost($base . "sections/", ["search" => "Computing"]);
$draft = $exceptionPost($path . "view/", []);
$input = [
    "expires_at" => "2026-08-20T17:00:00-04:00",
    "revision" => $draft["revision"],
    "reason" => "Fictional reviewed exception.",
];
$missing = $input;
unset($missing["expires_at"]);
if ($teachingPost($path . "review/", $missing)->getStatusCode() !== 422) {
    throw new RuntimeException("An omitted expiry was interpreted as revocation.");
}
$review = $exceptionPost($path . "review/", $input);
$missing = $review;
unset($missing["approval"]);
if ($teachingPost($path . "commit/", $missing)->getStatusCode() !== 422) {
    throw new RuntimeException("An omitted approval was interpreted as revocation.");
}
$receipt = $exceptionPost($path . "commit/", $review);
if ($receipt !== $exceptionPost($path . "commit/", $review)) {
    throw new RuntimeException("Prerequisite exception retry lost its original result.");
}
$timeline = $exceptionPost($path . "history/", []);
if (count($timeline["items"]) !== 1 || $timeline["items"][0]["reason"] !== $input["reason"]) {
    throw new RuntimeException("Curated prerequisite history lost its attributed reason.");
}
$before = $sis->sql->rows("sis_history_events");
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage", "sis:student:read"];
foreach (["view/", "history/", "review/", "commit/"] as $method) {
    if ($teachingPost($path . $method, $method === "review/" ? $input : $review)->getStatusCode() !== 403) {
        throw new RuntimeException("General registration authority granted the independent exception duty.");
    }
}
if ($before !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("Denied exception reads changed recorded facts.");
}
$sis->access->staff = true;
$draft = $exceptionPost($path . "view/", []);
$revoke = $exceptionPost($path . "review/", [
    "expires_at" => null,
    "revision" => $draft["revision"],
    "reason" => "Fictional revocation.",
]);
if ($exceptionPost($path . "commit/", $revoke)["status"] !== "revoked") {
    throw new RuntimeException("Explicit revocation did not commit.");
}
