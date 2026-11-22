<?php

declare(strict_types=1);

require_once __DIR__ . "/HistoricalFinancialFixture.php";
$f = new \Tests\Support\HistoricalFinancialFixture();
$a = $f->account();
$a->post(
    \Domain\Shared\ValueObject\EntityId::generate(),
    \Domain\Finance\Enum\EntryType::Charge,
    new \Domain\Finance\ValueObject\Money(10000, "USD"),
    $f->sis->access->actor,
);
$f->save($a, "2026-08-03T10:00:00Z");
$httpSis = $f->sis;
require __DIR__ . "/SisHttpFixture.php";
$definition = $f->definition();
$post = static function (
    string $path,
    array $data,
    int $expected = 200
) use (
    $teachingPost,
): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException(
            "Historical student finance returned " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$before = $sis->sql->rows("sis_history_events");
$preview = $post("/sis/report-studio/preview/", ["definition" => $definition, "offset" => 0]);
if (
    ($preview["items"][0]["c2"] ?? null) !== 10000 ||
    $preview["financial_as_of"] !== $definition["financial_as_of"]
) {
    throw new RuntimeException("The real historical financial report lost its cutoff or amount.");
}
$id = \Domain\Shared\ValueObject\EntityId::generate()->value;
$post(
    "/sis/report-studio/save/",
    [
        "report_id" => $id,
        "operation_id" => $id,
        "version" => -1,
        "name" => "Fictional historical student balances",
        "definition" => $definition,
    ],
    201,
);
$run = $post("/sis/report-studio/" . $id . "/run/", ["offset" => 0]);
if ($run["items"] !== $preview["items"] || $before !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("Saving or running a historical report changed financial history.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:manage", "sis:reporting:run", "sis:student:read"];
$post("/sis/report-studio/preview/", ["definition" => $definition, "offset" => 0], 403);
$post("/sis/report-studio/" . $id . "/run/", ["offset" => 0], 403);
$sis->access->grants = ["sis:reporting:manage", "sis:reporting:run", "sis:finance:read"];
$post("/sis/report-studio/preview/", ["definition" => $definition, "offset" => 0], 403);
