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
$supportApplication = $sis->submissionService()->apply(
    \Domain\Shared\ValueObject\EntityId::generate(),
    $supportStudent->personId,
    $sis->program,
    $sis->term,
);
require_once __DIR__ . '/StudentDecisionFixture.php';
$supportDecisions = new \Tests\Support\StudentDecisionFixture($sis);
require_once __DIR__ . '/EmploymentHistoryFixture.php';
$supportEmployment = new \Tests\Support\EmploymentHistoryFixture($sis);
require_once __DIR__ . '/TransferAwardFixture.php';
$supportTransfer = new \TransferAwardFixture($sis, $sis->dropCipher, $supportStudent->id);
$employmentFacts = $supportEmployment->facts();
$supportEvents = array_map($sis->sql->rows(...), [
    "sis_history_events",
    "sis_history_streams",
    "sis_history_operations",
    "sis_persons",
    "sis_students",
    "sis_student_accounts",
    "sis_account_entries",
    "sis_account_balances",
    "sis_applications",
    "sis_application_intake",
    "sis_student_standing",
    "sis_load_determinations",
    'sis_student_advisors',
    'sis_student_requirements',
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
    "credit" => $supportTransfer->credit->value,
    "account" => (string) $supportAccount->aggregateId(),
    "application" => $supportApplication->id->value,
    "standing" => $supportDecisions->pursuit->value,
    "load" => $supportDecisions->determination->value,
    'advisors' => $supportDecisions->pursuit->value,
    'requirements' => $supportDecisions->pursuit->value,
    'primary' => $supportEmployment->employee->value,
    'position' => $supportEmployment->position->value,
    'time' => $supportEmployment->entry->value,
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
        (in_array(
            $kind,
            ['credit', 'account', 'application', 'standing', 'load', 'primary', 'position', 'time'],
            true
        ) &&
            ($checked["result"]["status"] ?? null) !== "verified")
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
foreach (
    ['standing' => 'sis:standing:manage', 'load' => 'sis:load:determine',
    'advisors' => 'sis:advising:manage', 'requirements' => 'sis:degree-audit:assign'] as $kind => $permission
) {
    $sis->access->grants = ['sis:history:verify', 'sis:audit:read', 'sis:student:read'];
    $response = $teachingPost($supportBase . $kind . '/' . $supportCases[$kind] . '/run/', []);
    if ($response->getStatusCode() !== 403) {
        throw new RuntimeException('Support checking bypassed the independent student-decision duty.');
    }
}
foreach (['primary', 'position', 'time'] as $kind) {
    foreach (
        [['sis:history:verify', 'sis:audit:read'], ['sis:hr:manage', 'sis:audit:read'],
        ['sis:hr:manage', 'sis:history:verify']] as $grants
    ) {
        $sis->access->grants = $grants;
        $response = $teachingPost($supportBase . $kind . '/' . $supportCases[$kind] . '/run/', []);
        if ($response->getStatusCode() !== 403) {
            throw new RuntimeException('Employment support checking bypassed a required duty.');
        }
    }
}
$sis->access->actor = $supportEmployment->owner;
$sis->access->grants = [];
$response = $teachingPost($supportBase . 'time/' . $supportEmployment->entry->value . '/history/', []);
if ($response->getStatusCode() !== 403) {
    throw new RuntimeException('Employee ownership granted support-history authority.');
}
$sis->access->staff = true;
$sis->access->grants = [];
if (
    $teachingPost(
        $supportBase . "unknown/" . $supportStudent->id->value . "/run/",
        [],
    )->getStatusCode() !== 422 ||
    $employmentFacts !== $supportEmployment->facts() || $supportEvents !==
        array_map($sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_persons",
            "sis_students",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
            "sis_applications",
            "sis_application_intake",
            "sis_student_standing",
            "sis_load_determinations",
            'sis_student_advisors',
            'sis_student_requirements',
        ])
) {
    throw new RuntimeException("Support checks changed source facts or accepted an unknown scope.");
}
