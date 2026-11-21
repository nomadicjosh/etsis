<?php

declare(strict_types=1);

// Real router/providers and private CSRF transport, with fictional original records only.
$supportStudent = $sis->student($sis->user());
$supportAccount = \Domain\Finance\StudentAccount::open(
    new \Domain\Finance\ValueObject\AccountId(),
    $supportStudent->id,
    $sis->term,
    "USD",
);
$supportAccount->post(
    \Domain\Shared\ValueObject\EntityId::generate(),
    \Domain\Finance\Enum\EntryType::Charge,
    new \Domain\Finance\ValueObject\Money(1000, "USD"),
    $sis->access->actor,
    staffRecorded: true,
);
$sis->saveAccount($supportAccount);
$supportEvents = array_map($sis->sql->rows(...), [
    "sis_history_events",
    "sis_history_streams",
    "sis_history_operations",
    "sis_persons",
    "sis_students",
    "sis_student_accounts",
    "sis_account_entries",
    "sis_account_balances",
]);
$supportBase = "/sis/history-checks/";
$supportRead = static function (string $path, array $data) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        !str_contains($response->getHeaderLine("Content-Type"), "application/json")
    ) {
        throw new RuntimeException(
            "The private support-check route failed with status " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$supportCases = [
    "person" => $supportStudent->personId->value,
    "student" => $supportStudent->id->value,
    "account" => (string) $supportAccount->aggregateId(),
];
foreach ($supportCases as $kind => $id) {
    $base = $supportBase . $kind . "/" . $id . "/";
    $csrf = $post
        ->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $base . "run/"))
        ->withoutHeader("X-CSRF-Token")
        ->withParsedBody([]);
    if ($app->router->match($csrf)->getStatusCode() !== 403) {
        throw new RuntimeException("Support checks bypassed framework CSRF.");
    }
    $checked = $supportRead($base . "run/", ["events" => 2000]);
    if (
        ($checked["event_limit"] ?? null) !== 200 ||
        !isset($checked["receipt"]["reference"]) ||
        ($kind === "account" && ($checked["result"]["status"] ?? null) !== "verified")
    ) {
        throw new RuntimeException("The selected support check lost its fixed scope or allowance.");
    }
    $page = $supportRead($base . "history/", []);
    if (
        count($page["items"]) !== 1 ||
        isset($page["items"][0]["actor_id"]) ||
        isset($page["items"][0]["owner_user_id"])
    ) {
        throw new RuntimeException(
            "Support receipt history exposed routing/actor details or lost its observation.",
        );
    }
}
$sis->access->staff = false;
$sis->access->grants = ["sis:history:verify", "sis:audit:read"];
if (
    $teachingPost(
        $supportBase . "person/" . $supportStudent->personId->value . "/run/",
        [],
    )->getStatusCode() !== 403
) {
    throw new RuntimeException("Support duty widened person-profile access.");
}
$sis->access->staff = true;
$sis->access->grants = [];
if (
    $teachingPost(
        $supportBase . "unknown/" . $supportStudent->id->value . "/run/",
        [],
    )->getStatusCode() !== 422 ||
    $supportEvents !==
        array_map($sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_persons",
            "sis_students",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
        ])
) {
    throw new RuntimeException("Support checks changed source facts or accepted an unknown scope.");
}
