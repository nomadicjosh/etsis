<?php

declare(strict_types=1);

// Real service-provider wiring, CSRF and privacy over fictional SQLite only.
$student = $sis->student();
$person = $student->personId->value;
$readFee = static function (string $path) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        ),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Service fee read lost privacy.");
    }
    return $response;
};
$decodeFee = static function (
    \Psr\Http\Message\ResponseInterface $response,
): array {
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Service fee route failed: " . $response->getStatusCode(),
        );
    }
    return json_decode(
        (string) $response->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
$decodeFee($readFee("/sis/service-fees/policies/"));
$decodeFee(
    $teachingPost("/sis/service-fees/policies/transcript/", [
        "version" => 0,
        "minor" => 2500,
        "currency" => "USD",
        "waivers" => true,
    ]),
);
$fee = $decodeFee(
    $teachingPost("/sis/people/" . $person . "/service-fees/prepare/", [
        "purpose" => "transcript",
        "student_id" => $student->id->value,
    ]),
);
$base = "/sis/service-fees/" . $fee["fee_id"] . "/";
$decodeFee($readFee($base));
$review = $decodeFee(
    $teachingPost($base . "review/", [
        "action" => "waive",
        "details" => [],
        "reason" => "Fictional route waiver.",
    ]),
);
$decodeFee($teachingPost($base . "confirm/", $review));
$history = $decodeFee($teachingPost($base . "history/", []));
if (count($history["items"]) !== 2) {
    throw new RuntimeException("Fee history missing accepted decisions.");
}
$sis->access->staff = false;
if (
    $readFee($base)->getStatusCode() !== 403 ||
    $readFee("/sis/service-fees/policies/")->getStatusCode() !== 403
) {
    throw new RuntimeException("Fee authority was implied by ownership.");
}
$ownerBase = "/portal/api/service-fees/" . $fee["fee_id"] . "/";
if (!$decodeFee($readFee($ownerBase))["cleared"]) {
    throw new RuntimeException("Owner did not see fee clearance.");
}
$page = $decodeFee($teachingPost("/portal/api/service-fees/page/", []));
if (count($page["items"]) !== 1) {
    throw new RuntimeException("Owner fee list lost scope.");
}
$privateHistory = $decodeFee($teachingPost($ownerBase . "history/", []));
if (str_contains(json_encode($privateHistory), "Fictional route waiver.")) {
    throw new RuntimeException("Staff reason leaked to owner.");
}
$decodeFee($teachingPost($ownerBase . "checkout/", [])); // No provider call for an already cleared fee.
$sis->access->actor = $sis->user();
if ($readFee($ownerBase)->getStatusCode() !== 403) {
    throw new RuntimeException("Unrelated owner read a fee.");
}
$sis->access->staff = true;
$decodeFee($readFee($base));
