<?php

declare(strict_types=1);

// Actual routes and providers with fictional accounts, configuration and in-memory storage only.
require __DIR__ . "/SisHttpFixture.php";
$staff = $sis->access->actor;
$owner = $sis->user();
$person = $sis->student($owner)->personId;
$base = "/sis/ui/people/" . $person->value . "/update-requests/";
$id = \Domain\Shared\ValueObject\EntityId::generate();
$events = $sis->sql->rows("sis_history_events");
$created = $teachingPost($base, [
    "request_id" => $id->value,
    "area" => "addresses",
    "message" => "Fictional private next step.",
]);
if ($created->getStatusCode() !== 200) {
    throw new RuntimeException(
        "Profile request creation failed through actual CSRF-protected providers.",
    );
}
$read = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($base)));
if (
    $read->getStatusCode() !== 200 ||
    !str_contains($read->getHeaderLine("Cache-Control"), "no-store") ||
    str_contains((string) $read->getBody(), "phpdebugbar") ||
    !str_contains((string) $read->getBody(), "Fictional private next step.")
) {
    throw new RuntimeException("The private staff profile-request response failed.");
}
$sis->access->actor = $owner;
$sis->access->staff = false;
$sis->access->grants = [];
$ownRead = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("/portal/api/profile-update-requests/")),
);
if (
    $ownRead->getStatusCode() !== 200 ||
    str_contains((string) $ownRead->getBody(), "owner_user_id") ||
    str_contains((string) $ownRead->getBody(), "receipts") ||
    !str_contains($ownRead->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Owner request listing leaked internal data or failed ownership.");
}
$response = $teachingPost("/portal/api/profile-update-requests/" . $id->value . "/respond/", [
    "version" => 0,
    "message" => "Fictional details already correct.",
]);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException("Owner response failed its actual private route.");
}
$denied = $teachingPost($base . $id->value . "/resolve/", [
    "version" => 1,
    "action" => "close",
    "message" => "Cannot self-approve.",
]);
if ($denied->getStatusCode() !== 403) {
    throw new RuntimeException("Owning a request granted staff closure authority.");
}
$sis->access->actor = $staff;
$sis->access->staff = true;
$closed = $teachingPost($base . $id->value . "/resolve/", [
    "version" => 1,
    "action" => "close",
    "message" => "Fictional reviewed outcome.",
]);
if ($closed->getStatusCode() !== 200) {
    throw new RuntimeException("Staff closure failed the actual provider route.");
}
$finished = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri($base))->withQueryParams(["finished" => "1"]),
);
$data = json_decode((string) $finished->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $finished->getStatusCode() !== 200 ||
    ($data["items"][0]["status"] ?? null) !== "closed" ||
    str_contains((string) $finished->getBody(), "phpdebugbar") ||
    $events !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "Finished paging changed profile facts or lost the private query boundary.",
    );
}
