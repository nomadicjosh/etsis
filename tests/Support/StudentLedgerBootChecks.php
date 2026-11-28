<?php

declare(strict_types=1);

use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

$decodeLedger = static function (
    \Psr\Http\Message\ResponseInterface $response,
): array {
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Accounting route returned HTTP " . $response->getStatusCode(),
        );
    }
    return json_decode(
        (string) $response->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
$ledger = $app->make(LedgerService::class);
$income = $ledger->addAccount("HTTP.INCOME", "Fictional income", "USD", 'income');
$bank = $ledger->addAccount("HTTP.BANK", "Fictional bank", "USD", 'asset');
$review = $decodeLedger(
    $teachingPost("/sis/accounting/mapping/review/", [
        "currency" => "USD",
        "timezone" => "America/Los_Angeles",
        "receivable_code" => "HTTP.AR",
        "receivable_title" => "Fictional receivable",
        "charge" => $income->value,
        "payment" => $bank->value,
        "refund" => $bank->value,
        "charge_sources" => [],
        "payment_methods" => [],
        "reason" => "Approve fictional mapping.",
    ]),
);
$decodeLedger(
    $teachingPost("/sis/accounting/confirm/", [
        "review_token" => $review["review_token"],
    ]),
);
$review = $decodeLedger(
    $teachingPost("/sis/accounting/periods/review/", [
        "currency" => "USD",
        "label" => "Fictional August",
        "starts_on" => "2026-08-01",
        "ends_on" => "2026-08-31",
        "reason" => "Open fictional accounting period.",
    ]),
);
$period = $decodeLedger(
    $teachingPost("/sis/accounting/confirm/", [
        "review_token" => $review["review_token"],
    ]),
)["id"];
$finance = $app->make(\Domain\Finance\Service\StudentAccountService::class);
$liability = $ledger->addAccount('HTTP.UNEARN', 'Fictional unearned fees', 'USD', 'liability');
$expense = $ledger->addAccount('HTTP.COST', 'Fictional processing expense', 'USD', 'expense');
$feeInput = ['currency' => 'USD', 'cash' => $bank->value, 'liability' => $liability->value,
    'expense' => $expense->value, 'application' => $income->value, 'transcript' => $income->value,
    'recognition' => 'acceptance', 'reason' => 'Approve fictional fee recognition.'];
$feeReview = $decodeLedger($teachingPost('/sis/accounting/fees/review/', $feeInput));
$decodeLedger($teachingPost('/sis/accounting/confirm/', ['review_token' => $feeReview['review_token']]));
$costInput = ['currency' => 'USD', 'amount_minor' => 150, 'asset' => $bank->value,
    'reference' => 'HTTP.COST', 'posted_on' => '2026-08-10', 'reason' => 'Actual fictional processing cost.'];
$costReview = $decodeLedger($teachingPost('/sis/accounting/expense/review/', $costInput));
$cost = $decodeLedger($teachingPost('/sis/accounting/confirm/', ['review_token' => $costReview['review_token']]));
$costRetry = $decodeLedger($teachingPost('/sis/accounting/confirm/', ['review_token' => $costReview['review_token']]));
if ($costRetry !== $cost) {
    throw new RuntimeException('Processing expense retry changed its original result.');
}
$missingCostCsrf = $app->router->match($post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/accounting/expense/review/'
))->withParsedBody($costInput));
if ($missingCostCsrf->getStatusCode() !== 403) {
    throw new RuntimeException('Processing expense omitted CSRF protection.');
}
$account = $finance->open($sis->student()->id, $sis->term, "USD");
$finance->post(
    $account->aggregateId(),
    EntityId::generate(),
    EntryType::Charge,
    new Money(2500, "USD"),
    $account->playhead(),
    "Fictional provider posting.",
);
$base = "/sis/accounting/periods/" . $period . "/";
$scan = $decodeLedger($teachingPost($base . "reconcile/", []));
if (!$scan["done"] || $scan["ar_net_minor"] !== "2500") {
    throw new RuntimeException(
        "The actual accounting providers did not reconcile their posting.",
    );
}
$review = $decodeLedger(
    $teachingPost($base . "review/", [
        "action" => "close",
        "reconciliation" => $scan["continuation"],
        "reason" => "Close reconciled fictional period.",
    ]),
);
$decodeLedger(
    $teachingPost("/sis/accounting/confirm/", [
        "review_token" => $review["review_token"],
    ]),
);
$readLedger = $app->router->match(
    $get
        ->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid/sis/accounting/",
            ),
        )
        ->withQueryParams(["currency" => "USD"]),
);
if (
    $readLedger->getStatusCode() !== 200 ||
    !str_contains($readLedger->getHeaderLine("Cache-Control"), "no-store") ||
    $readLedger->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "Accounting overview lost its private read boundary.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:finance:ledger"];
if (
    $teachingPost('/sis/accounting/fees/review/', $feeInput)->getStatusCode() !== 403
    || $teachingPost('/sis/accounting/confirm/', [
        'review_token' => $feeReview['review_token'],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException('Service-fee mapping lost its independent configuration duty.');
}
if (
    $teachingPost($base . "reconcile/", [])->getStatusCode() !== 403 ||
    $teachingPost("/sis/accounting/confirm/", [
        "review_token" => $review["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Accounting lost its independent current duties.",
    );
}
