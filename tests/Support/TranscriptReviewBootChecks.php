<?php

declare(strict_types=1);

// Actual providers/router/CSRF; only fictional SQLite academic records and an injected local renderer.
$app->delegate(
    \Domain\Transcript\ValueObject\TranscriptIssuer::class,
    static fn() => new \Domain\Transcript\ValueObject\TranscriptIssuer(
        "Fictional University",
        "Fictional campus",
    ),
);
$app->delegate(
    \Domain\Transcript\Service\TranscriptDocumentRenderer::class,
    static fn() => new class implements
        \Domain\Transcript\Service\TranscriptDocumentRenderer
    {
        public function pdf(#[\SensitiveParameter] array $document): string
        {
            return "%PDF-1.4 Fictional private transcript";
        }
        public function html(#[\SensitiveParameter] array $document): string
        {
            return "<p>Fictional academic transcript</p>";
        }
    },
);
$student = $sis->student();
$sis->service->register($student->id, $sis->section(20, 0)->id);
$requestId = \Domain\Shared\ValueObject\EntityId::generate();
$sis->sql->insert("sis_transcript_requests", [
    "id" => $requestId->value,
    "student_id" => $student->id->value,
    "owner_user_id" => $sis->admissions->person($student->personId)->ownerUserId
        ->value,
    "delivery" => "pickup",
    "encrypted_recipient" => $sis->dropCipher->seal(""),
    "status" => "pending",
    "requested_at" => $sis->clock->now()->format(DATE_ATOM),
    "version" => 0,
]);
$base = "/sis/transcript-requests/" . $requestId->value;
$reviewResponse = $teachingPost($base . "/review/", []);
$review = json_decode(
    (string) $reviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $reviewResponse->getStatusCode() !== 200 ||
    !is_string($review["review_token"] ?? null) ||
    ($review['layout']['code'] ?? null) !== 'classic-letter' || count($review['layouts'] ?? []) !== 4
) {
    throw new RuntimeException(
        "The actual transcript provider failed to issue a private preparation review.",
    );
}
$previewCommand = ['operation_id' => $review['operation_id'], 'revision' => $review['revision'],
    'review_token' => $review['review_token'], 'layout_code' => $review['layout']['code']];
$preview = $teachingPost($base . '/preview/', $previewCommand);
if (
    $preview->getStatusCode() !== 200 ||
    !str_starts_with($preview->getHeaderLine('Content-Type'), 'text/html') ||
    !str_contains($preview->getHeaderLine('Content-Security-Policy'), 'sandbox') ||
    $sis->sql->rows('sis_transcript_documents') !== []
) {
    throw new RuntimeException('The protected layout preview created a document or lost its private transport.');
}
if (
    $teachingPost($base . '/review/', ['layout_code' => 'not-installed'])->getStatusCode() !== 422 ||
    $teachingPost($base . '/preview/', array_replace($previewCommand, ['layout_code' => 'modern-a4']))
        ->getStatusCode() !== 409
) {
    throw new RuntimeException('Unregistered or unreviewed transcript layouts were accepted.');
}
$command = [
    "operation_id" => $review["operation_id"],
    "revision" => $review["revision"],
    "release_reviewed" => true,
];
$missing = $teachingPost($base . "/prepare/", $command);
if (
    $missing->getStatusCode() !== 422 ||
    $sis->sql->rows("sis_transcript_documents") !== []
) {
    throw new RuntimeException("An unreviewed transcript was prepared.");
}
$command["review_token"] = $review["review_token"];
$prepared = $teachingPost($base . "/prepare/", $command);
$sis->clock->time = $sis->clock->now()->modify("+1 day");
$retried = $teachingPost($base . "/prepare/", $command);
if (
    $prepared->getStatusCode() !== 201 ||
    (string) $prepared->getBody() !== (string) $retried->getBody()
) {
    throw new RuntimeException(
        "An accepted preparation could not return its original expired-review result.",
    );
}
$path = $base . "/documents/" . $review["operation_id"];
$input = [
    "version" => 1,
    "delivered_at" => $sis->clock->now()->format(DATE_ATOM),
    "evidence" => "Fictional private handoff reference",
];
$deliveryResponse = $teachingPost($path . "/delivery-review/", $input);
$delivery = json_decode(
    (string) $deliveryResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $deliveryResponse->getStatusCode() !== 200 ||
    $sis->sql->rows("sis_transcript_deliveries") !== []
) {
    throw new RuntimeException(
        "Reviewing evidence unexpectedly fulfilled the transcript.",
    );
}
$input["operation_id"] = $delivery["operation_id"];
if ($teachingPost($path . "/deliver/", $input)->getStatusCode() !== 422) {
    throw new RuntimeException("Delivery confirmation bypassed its review.");
}
$input["review_token"] = $delivery["review_token"];
$saved = $teachingPost($path . "/deliver/", $input);
$sis->clock->time = $sis->clock->now()->modify("+1 day");
$again = $teachingPost($path . "/deliver/", $input);
if (
    $saved->getStatusCode() !== 200 ||
    (string) $saved->getBody() !== (string) $again->getBody() ||
    count($sis->sql->rows("sis_transcript_documents")) !== 1 ||
    count($sis->sql->rows("sis_transcript_deliveries")) !== 1
) {
    throw new RuntimeException(
        "Reviewed delivery or its original retry failed.",
    );
}
$input["evidence"] = "A different fictional handoff";
if ($teachingPost($path . "/deliver/", $input)->getStatusCode() !== 409) {
    throw new RuntimeException(
        "An accepted delivery was changed through a retry.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:transcript:manage"];
if (
    $teachingPost($path . "/delivery-review/", $input)->getStatusCode() !==
        403 ||
    $teachingPost($path . "/pdf/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Transcript office access bypassed the academic-read grant.",
    );
}
