<?php

declare(strict_types=1);

require __DIR__ . "/SisHttpFixture.php";
$owner = $sis->user();
$employee = $sis
    ->timesheetService()
    ->addEmployee($sis->student($owner)->personId, "Fictional library employee");
$job = new \Domain\HumanResources\JobDefinition(
    \Domain\Shared\ValueObject\EntityId::generate(),
    "LIB.ASST",
    "Fictional library assistant",
    "USD",
    "hourly",
    true,
);
$sis->hrLibrary()->save($job, null);
$position = \Domain\Shared\ValueObject\EntityId::generate();
$sis->positions()->assign(
    $employee,
    $position,
    $job->id,
    0,
    null,
    "2026-08-01",
    null,
    2500,
    1200,
    "Fictional salary reason",
);
$base = "/sis/employees/" . $employee->value . "/primary-position/";
$read = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($path)));
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Primary-position read exposed caching or debugging.");
    }
    return $response;
};
$summary = $read($base);
if ($summary->getStatusCode() !== 200) {
    throw new RuntimeException("Primary-position summary route failed.");
}
$state = json_decode((string) $summary->getBody(), true, flags: JSON_THROW_ON_ERROR);
$command = [
    "version" => $state["version"],
    "operation_id" => $state["operation_id"],
    "position_id" => $position->value,
    "position_version" => 1,
    "reason" => "Fictional private primary reason",
];
$review = $teachingPost($base . "review/", $command);
if ($review->getStatusCode() !== 200) {
    throw new RuntimeException("Primary-position review route failed: " . $review->getStatusCode());
}
$proof = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
$confirm = $command + ["review_token" => $proof["review_token"]];
if (
    $teachingPost($base . "confirm/", $confirm)->getStatusCode() !== 200 ||
    $teachingPost($base . "history/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("Primary-position confirmation/history route failed.");
}
$events = $sis->sql->rows("sis_history_events");
if (
    $teachingPost($base . "confirm/", $confirm)->getStatusCode() !== 200 ||
    $events !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException("Primary-position retry duplicated history.");
}
$sis->access->staff = false;
$sis->access->actor = $owner;
$summary = $read($base);
if (
    $summary->getStatusCode() !== 200 ||
    preg_match("/rate_minor|weekly_minutes|reason|actor_id/", (string) $summary->getBody()) ||
    $teachingPost($base . "history/", [])->getStatusCode() !== 403 ||
    $teachingPost($base . "review/", $command)->getStatusCode() !== 403 ||
    $teachingPost($base . "confirm/", $confirm)->getStatusCode() !== 403
) {
    throw new RuntimeException("Primary-position owner privacy or HR authority failed.");
}
$sis->access->actor = $sis->user();
if ($read($base)->getStatusCode() !== 403) {
    throw new RuntimeException("An unrelated owner read a primary designation.");
}
