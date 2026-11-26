<?php

declare(strict_types=1);

// Actual providers/routes/CSRF, fictional SQLite records and local injected rendering only.
$app->delegate(
    \Domain\Transcript\ValueObject\TranscriptIssuer::class,
    static fn() => new \Domain\Transcript\ValueObject\TranscriptIssuer("Fictional University", "Fictional campus"),
);
$app->delegate(
    \Domain\Transcript\Service\TranscriptDocumentRenderer::class,
    static fn() => new class implements \Domain\Transcript\Service\TranscriptDocumentRenderer {
        public function pdf(#[\SensitiveParameter] array $document): string
        {
            return "%PDF-1.4 Fictional private transcript";
        }
        public function html(#[\SensitiveParameter] array $document): string
        {
            return "<p>Fictional private transcript</p>";
        }
    },
);
$privateGet = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Transcript notification reads lost private transport protection.");
    }
    return $response;
};
$staff = $sis->access->actor;
$student = $sis->student($sis->user());
$sis->service->register($student->id, $sis->section(20, 0)->id);
$owner = $sis->admissions->person($student->personId)->ownerUserId;
$sis->access->actor = $owner;
$sis->access->staff = false;
$request = \Domain\Shared\ValueObject\EntityId::generate();
$created = $teachingPost("/portal/api/transcripts/", [
    "request_id" => $request->value,
    "student_id" => $student->id->value,
    "delivery" => "pickup",
    "recipient" => "",
]);
if ($created->getStatusCode() !== 201) {
    throw new RuntimeException("The owner could not submit a fictional transcript request.");
}
$sis->access->actor = $staff;
$sis->access->staff = true;
$base = "/sis/transcript-requests/" . $request->value;
$response = $teachingPost($base . "/review/", []);
$review = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || !isset($review["review_token"])) {
    throw new RuntimeException("The actual provider did not issue a transcript preparation review.");
}
$prepare = [
    "operation_id" => $review["operation_id"],
    "revision" => $review["revision"],
    "review_token" => $review["review_token"],
    "release_reviewed" => true,
];
if ($teachingPost($base . "/prepare/", $prepare)->getStatusCode() !== 201) {
    throw new RuntimeException("The actual provider could not prepare the private fictional transcript.");
}
$row = $sis->sql->rows("sis_transcript_updates")[0] ?? null;
if ($row === null || $row["owner_user_id"] !== $owner->value || $row["status"] !== "ready") {
    throw new RuntimeException("An accepted preparation did not atomically publish its owner notice.");
}
$sis->access->actor = $owner;
$sis->access->staff = false;
$list = $privateGet("/portal/api/transcript-updates/");
$items = json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $list->getStatusCode() !== 200 ||
    count($items["items"] ?? []) !== 1 ||
    isset($items["items"][0]["document_id"])
) {
    throw new RuntimeException("Transcript notices lost their minimized owner list.");
}
$path = "/portal/api/transcript-updates/" . $row["id"] . "/";
$detail = $privateGet($path);
$data = json_decode((string) $detail->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($detail->getStatusCode() !== 200 || $data["status"] !== "ready" || count($data) !== 4) {
    throw new RuntimeException("The private transcript notice returned more than current status.");
}
foreach (["read", "delete"] as $action) {
    if ($teachingPost($path . $action . "/", ["revision" => 1])->getStatusCode() !== 200) {
        throw new RuntimeException("A private transcript notice mutation failed.");
    }
}
if ($sis->sql->rows("sis_transcript_updates") !== [] || count($sis->sql->rows("sis_transcript_documents")) !== 1) {
    throw new RuntimeException("Deleting a transcript notice changed its protected document or retained its row.");
}
$sis->access->actor = $staff;
$sis->access->staff = true;
if (
    $teachingPost($base . "/prepare/", $prepare)->getStatusCode() !== 201 ||
    $sis->sql->rows("sis_transcript_updates") !== []
) {
    throw new RuntimeException("An accepted transcript preparation retry recreated a deleted notice.");
}
if ($privateGet($path)->getStatusCode() !== 403) {
    throw new RuntimeException("A staff duty widened owner-only transcript-notice access.");
}
