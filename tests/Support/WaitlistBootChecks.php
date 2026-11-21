<?php

declare(strict_types=1);

$read = static function (string $path, int $status = 200) use ($app, $get): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Waitlist read lost authorization, private response or debug suppression.",
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$postData = static function (
    string $path,
    array $data,
    int $status = 200
) use (
    $teachingPost,
): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Waitlist mutation expected " . $status . ", got " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$section = $sis->section(capacity: 1);
$filler = $sis->student($sis->user());
$sis->service->register($filler->id, $section->id, true);
$path = "/sis/sections/" . $section->id->value . "/waitlist/";
$policy = $read($path . "policy/");
if ($policy["policy"]["enabled"]) {
    throw new RuntimeException("A section implicitly allowed waitlisting.");
}
$policy["policy"]["enabled"] = true;
$proof = $postData($path . "policy/review/", [
    "policy" => $policy["policy"],
    "version" => 0,
    "reason" => "Fictional section waitlisting approval.",
]);
$postData($path . "policy/confirm/", $proof);
$owner = $sis->user();
$student = $sis->student($owner);
$other = $sis->student($sis->user());
$sis->access->actor = $owner;
$sis->access->staff = false;
$ownerPath = "/portal/api/waitlists/" . $student->id->value . "/";
$sectionPath = $ownerPath . "sections/" . $section->id->value . "/";
$proof = $postData($sectionPath . "review/", ["action" => "requested", "entry_id" => null]);
$accepted = $postData($sectionPath . "confirm/", $proof);
if ($read($sectionPath)["rank"] !== 1 || count($read($ownerPath)["items"]) !== 1) {
    throw new RuntimeException("Owner waitlist place was not retained.");
}
$read("/portal/api/waitlists/" . $other->id->value . "/", 403);
$postData(
    $path . "review/",
    ["student_id" => $student->id->value, "action" => "requested", "entry_id" => null],
    403,
);
$sis->access->staff = true;
$read("/portal/api/waitlists/" . $other->id->value . "/", 403);
$queue = $read($path);
if ($queue["items"][0]["student_id"] !== $student->id->value) {
    throw new RuntimeException("Staff queue lost accepted order.");
}
$removed = $postData($path . "review/", [
    "student_id" => $student->id->value,
    "entry_id" => $accepted["entry_id"],
    "action" => "removed",
    "reason" => "Fictional restricted removal reason.",
]);
$postData($path . "confirm/", ["student_id" => $student->id->value] + $removed);
$sis->access->staff = false;
$mine = $read($ownerPath);
if (
    $mine["items"][0]["status"] !== "removed" ||
    str_contains(json_encode($mine, JSON_THROW_ON_ERROR), "restricted removal reason")
) {
    throw new RuntimeException("Owner request history lost its safe outcome.");
}
echo "Waitlist route checks passed.\n";
