<?php

declare(strict_types=1);

// Fictional records through the real container, private router and framework CSRF.
$checkInBase =
"/sis/students/" . $advisingStudent->id->value . "/programs/" . $advisingPursuit . "/checkins/";
$checkInCsrf = $get
    ->withMethod("POST")
    ->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $checkInBase . "list/"))
    ->withoutHeader("X-CSRF-Token")
    ->withParsedBody([]);
if ($app->router->match($checkInCsrf)->getStatusCode() !== 403) {
    throw new RuntimeException("Advising check-in reads bypassed framework CSRF.");
}
$checkInPost = static function (string $path, array $data) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        !str_contains($response->getHeaderLine("Content-Type"), "application/json")
    ) {
        throw new RuntimeException(
            "Private advising check-in route failed with status " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$checkInEvents = $sis->sql->rows("sis_history_events");
$checkInPage = $checkInPost($checkInBase . "list/", []);
$checkInCommand = [
    "checkin_id" => $checkInPage["new_id"],
    "held_on" => "2026-08-10",
    "due_on" => null,
    "next_step" => "Fictional student-visible next step.",
    "private_note" => "Fictional private\nadvising note.",
];
if (
    $teachingPost(
        $checkInBase . "record/",
        array_diff_key($checkInCommand, ["due_on" => true]),
    )->getStatusCode() !== 422
) {
    throw new RuntimeException("Check-in recording must require an explicit due-date choice.");
}
$checkInCreated = $checkInPost($checkInBase . "record/", $checkInCommand);
$checkInPost($checkInBase . "record/", $checkInCommand);
$checkInStudentBase =
"/portal/api/students/" .
$advisingStudent->id->value .
"/programs/" .
$advisingPursuit .
"/checkins/";
if ($teachingPost($checkInStudentBase . "list/", [])->getStatusCode() !== 403) {
    throw new RuntimeException("Staff authority widened the owner check-in route.");
}
$checkInOwner = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
$sis->access->actor = $checkInOwner;
$sis->access->staff = false;
$checkInOwnerPage = $checkInPost($checkInStudentBase . "list/", []);
$readAdvisingUpdates = static function (string $query = '') use ($app, $get): array {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid/portal/api/advising-updates/' . $query
    )));
    if (
        $response->getStatusCode() !== 200 || $response->hasHeader('phpdebugbar-id')
        || !str_contains($response->getHeaderLine('Cache-Control'), 'private, no-store')
    ) {
        throw new RuntimeException('Advising updates lost their actual private provider boundary.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$advisingNotices = $readAdvisingUpdates();
$advisingNotice = $advisingNotices['items'][0] ?? [];
if (
    count($advisingNotices['items']) !== 1 || ($advisingNotice['revision'] ?? null) !== 0
    || str_contains(json_encode($advisingNotices), 'Fictional')
    || str_contains(json_encode($advisingNotices), 'private_note')
) {
    throw new RuntimeException('Advising notices exposed conversation text or lost a genuine new check-in.');
}
$advisingNoticePath = '/portal/api/advising-updates/' . $advisingNotice['id'];
$advisingNoticeCsrf = $get->withMethod('POST')->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $advisingNoticePath . '/read/'
))->withoutHeader('X-CSRF-Token')->withParsedBody(['revision' => 0]);
if ($app->router->match($advisingNoticeCsrf)->getStatusCode() !== 403) {
    throw new RuntimeException('Advising notice actions bypassed framework CSRF.');
}
$checkInPost($advisingNoticePath . '/read/', ['revision' => 0]);
$checkInPost($advisingNoticePath . '/delete/', ['revision' => 0]);
$checkInPost($advisingNoticePath . '/delete/', ['revision' => 0]);
if ($readAdvisingUpdates()['items'] !== []) {
    throw new RuntimeException('Deleted advising notifications persisted.');
}
if (
    isset($checkInOwnerPage["items"][0]["private_note"]) ||
    !str_contains(json_encode($checkInOwnerPage), "student-visible")
) {
    throw new RuntimeException("Owner check-ins exposed a private note or lost the next step.");
}
$checkInPost($checkInStudentBase . "act/", [
    "checkin_id" => $checkInCreated["id"],
    "action" => "respond",
    "version" => 0,
    "message" => "Fictional owner response.",
]);
if (
    $teachingPost($checkInStudentBase . "act/", [
        "checkin_id" => $checkInCreated["id"],
        "action" => "close",
        "version" => 1,
        "message" => "Invalid student closure.",
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("A student could close advising follow-up.");
}
$sis->access->actor = $advisingPerson->ownerUserId;
$checkInAdvisorBase = str_replace("/portal/api/", "/portal/api/advising/", $checkInStudentBase);
if ($teachingPost($checkInAdvisorBase . "list/", [])->getStatusCode() !== 403) {
    throw new RuntimeException("Advising assignment alone granted working check-in duty.");
}
$sis->access->grants = ["sis:advising:checkin"];
$checkInPost($checkInAdvisorBase . "list/", []);
$checkInPost($checkInAdvisorBase . "act/", [
    "checkin_id" => $checkInCreated["id"],
    "action" => "close",
    "version" => 1,
    "message" => "Fictional reviewed closure.",
]);
$sis->access->actor = $checkInOwner;
$sis->access->grants = [];
$checkInFinished = $checkInPost($checkInStudentBase . "list/", ["finished" => true]);
$selectedCheckIn = $checkInPost($checkInStudentBase . 'list/', ['checkin_id' => $checkInCreated['id']]);
$resolutionNotice = $readAdvisingUpdates()['items'][0] ?? [];
if (
    ($resolutionNotice['status'] ?? null) !== 'closed' || ($resolutionNotice['revision'] ?? null) !== 2
    || ($selectedCheckIn['items'][0]['id'] ?? null) !== $checkInCreated['id']
    || count($selectedCheckIn['items']) !== 1 || isset($selectedCheckIn['items'][0]['private_note'])
    || $selectedCheckIn['can_record']
) {
    throw new RuntimeException('A resolution notice lost the exact private finished-check-in handoff.');
}
if (
    ($checkInFinished["items"][0]["status"] ?? null) !== "closed" ||
    $checkInEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "Working check-ins lost closure or changed authoritative academic history.",
    );
}
$sis->access->actor = $advisingActor;
$sis->access->staff = true;

require __DIR__ . '/AdvisingFollowUpReportBootChecks.php';
