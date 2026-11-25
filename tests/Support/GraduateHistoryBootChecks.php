<?php

declare(strict_types=1);

// Isolated fictional records; actual console/provider/HTTP/privacy/CSRF boundaries.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
$invalidOptions = [
    ["--graduate-policy" => true, "--streams" => "10"],
    ["--graduate-policy" => true, "--cursor" => "PRIVATE cursor"],
    ["--graduate-policy" => true, "--person" => "00000000000000000000000000"],
    ["--graduate-policy" => true, "--graduate-approvals" => "00000000000000000000000000"],
    ["--graduate-approvals" => "PRIVATE invalid"],
    ["--graduate-approvals" => "00000000000000000000000000", "--events" => "2001"],
    ["--graduate-approvals" => "00000000000000000000000000", "--section" => "00000000000000000000000000"],
];
foreach ($invalidOptions as $options) {
    if (
        $early->execute($options + ["--json" => true]) !== 2 ||
        str_contains($early->getDisplay(), "PRIVATE")
    ) {
        throw new RuntimeException("Invalid graduate selectors resolved storage or exposed private input.");
    }
}
require_once __DIR__ . "/GraduateRegistrationFixture.php";
$f = new \Tests\Support\GraduateRegistrationFixture();
$s = $f->sis;
$f->decide("advisor_approve");
$f->decide("registrar_override");
$httpSis = $s;
$kernel->bootstrap();
require __DIR__ . "/SisHttpFixture.php";
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()["sis:history:verify"]);
$before = \EventStoreFixture::historyDigest($s->sql);
$s->connection->pdo->exec("PRAGMA query_only = ON");
foreach ([["--graduate-policy" => true], ["--graduate-approvals" => $f->pursuit->value]] as $options) {
    if ($command->execute($options + ["--json" => true]) !== 0) {
        throw new RuntimeException("The actual graduate history console provider failed.");
    }
    $result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $result["status"] !== "verified" ||
        $result["records_changed"] ||
        str_contains($command->getDisplay(), "Fictional")
    ) {
        throw new RuntimeException(
            "Graduate history console output exposed private values or lost its bounded result.",
        );
    }
    if ($command->execute($options) !== 0 || str_contains($command->getDisplay(), "minimum_gpa")) {
        throw new RuntimeException("Graduate history plain output did not preserve privacy.");
    }
}
if (
    $command->execute(["--graduate-approvals" => $f->pursuit->value, "--events" => "2", "--json" => true]) !==
        3 ||
    $before !== \EventStoreFixture::historyDigest($s->sql)
) {
    throw new RuntimeException("Graduate verification lost its shared limit or changed protected facts.");
}
$s->connection->pdo->exec("PRAGMA query_only = OFF");
$s->access->staff = false;
$subjects = [
    "gradpolicy" => new \Domain\Shared\ValueObject\EntityId(
        \Domain\Registration\GraduateRegistrationPolicy::ID,
    ),
    "graduate" => $f->pursuit,
];
foreach ($subjects as $kind => $id) {
    $permissions = [
        "sis:history:verify",
        "sis:audit:read",
        ...\Domain\Support\Enum\HistoryCheckSubject::from($kind)->permissions(),
    ];
    $base = "/sis/history-checks/" . $kind . "/" . $id->value . "/";
    foreach ($permissions as $missing) {
        $s->access->grants = array_values(array_diff($permissions, [$missing]));
        foreach (["run/", "history/"] as $action) {
            if ($teachingPost($base . $action, [])->getStatusCode() !== 403) {
                throw new RuntimeException("Graduate support bypassed an independent duty.");
            }
        }
    }
    $s->access->grants = $permissions;
    $response = $teachingPost($base . "run/", ["events" => 2000]);
    $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $response->getStatusCode() !== 200 ||
        ($data["result"]["status"] ?? null) !== "verified" ||
        $data["event_limit"] !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Graduate support lost its fixed limit, private response or actual provider result.",
        );
    }
    $historyPage = $teachingPost($base . "history/", []);
    $page = json_decode((string) $historyPage->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($historyPage->getStatusCode() !== 200 || $page["items"] !== [$data["receipt"]]) {
        throw new RuntimeException("Graduate support receipts lost their canonical scope.");
    }
}
if ($before !== \EventStoreFixture::historyDigest($s->sql)) {
    throw new RuntimeException("Support observations changed graduate decisions.");
}
$rows = $s->sql->rows("sis_history_checks");
if (count($rows) !== 2) {
    throw new RuntimeException("Graduate support did not retain exactly its two minimal receipts.");
}
foreach ($rows as $row) {
    if (
        $row["subject_type"] === "gradpolicy" &&
        ($row["person_id"] !== null || $row["student_id"] !== null)
    ) {
        throw new RuntimeException("An institutional policy observation fabricated personal routing.");
    }
}
