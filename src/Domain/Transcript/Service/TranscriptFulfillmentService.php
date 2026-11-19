<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Transcript\Repository\TranscriptRepository;
use Domain\Transcript\ValueObject\TranscriptIssuer;

final readonly class TranscriptFulfillmentService
{
    public function __construct(
        private TranscriptRepository $records,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private TranscriptDocumentRenderer $renderer,
        private TranscriptIssuer $issuer,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private ReviewProof $reviews,
        private AcademicInputVerifier $academicInputs,
    ) {
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:transcript:manage");
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            ($expected !== null && $actor->value !== $expected->value)
        ) {
            throw new AccessDeniedException("The transcript staff account changed. Review again.");
        }
        return $actor;
    }

    /** @return array<string, mixed> */
    public function review(EntityId $request): array
    {
        $actor = $this->authorize();
        $routing = $this->routing($request);
        return $this->transactions->run(function () use ($request, $routing, $actor): array {
            $this->lock($request, $routing);
            $this->authorize($actor);
            $context = $this->context($request);
            $this->audit->record($actor, "transcript.preparation_reviewed", $request);
            $this->authorize($actor);
            $revision = $this->revision($context);
            $operation = EntityId::generate();
            return $context + [
                "revision" => $revision,
                "operation_id" => $operation->value,
                "review_token" => $this->reviews->issue(
                    "transcript.review",
                    "prepare",
                    $request,
                    $actor,
                    $operation,
                    $revision,
                ),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function prepare(
        EntityId $request,
        EntityId $operation,
        string $revision,
        bool $releaseReviewed,
        #[\SensitiveParameter] string $reviewToken,
    ): array {
        $actor = $this->authorize();
        if (!$releaseReviewed) {
            throw new ValidationException([
                [
                    "field" => "release_reviewed",
                    "message" => "Confirm the academic record and institutional release checks.",
                ],
            ]);
        }
        $existing = $this->records->document($operation);
        if ($existing !== null) {
            return $this->replay($existing, $request, $actor, $revision);
        }
        $this->reviews->validate(
            $reviewToken,
            "transcript.review",
            "prepare",
            $request,
            $actor,
            $operation,
            $revision,
        );
        $routing = $this->routing($request);
        $preparation = $this->transactions->run(function () use (
            $request,
            $routing,
            $actor,
            $revision,
            $reviewToken,
            $operation,
        ): array {
            $this->lock($request, $routing);
            $this->authorize($actor);
            $existing = $this->records->document($operation);
            if ($existing !== null) {
                return [
                    "existing" => $this->replay($existing, $request, $actor, $revision),
                ];
            }
            $context = $this->context($request);
            $this->check($this->revision($context), $revision);
            $this->reviews->validate(
                $reviewToken,
                "transcript.review",
                "prepare",
                $request,
                $actor,
                $operation,
                $revision,
            );
            $this->authorize($actor);
            return ["context" => $context];
        });
        if (isset($preparation["existing"])) {
            return $preparation["existing"];
        }
        $context = $preparation["context"];

        if (trim($this->issuer->name) === "") {
            throw new ValidationException([
                [
                    "field" => "issuer",
                    "message" => "Configure the institutional transcript issuer before preparing a document.",
                ],
            ]);
        }
        // Rendering runs outside database locks. A second locked review validates the exact snapshot before saving.
        $document = $context["snapshot"] + [
            "document_id" => $operation->value,
            "issued_at" => $this->now(),
            "preview" => false,
        ];
        $pdf = $this->renderer->pdf($document);
        if (!str_starts_with($pdf, "%PDF-") || strlen($pdf) > 4 * 1024 * 1024) {
            throw new ValidationException([
                [
                    "field" => "document",
                    "message" => "The generated document exceeds the supported PDF size or is invalid.",
                ],
            ]);
        }
        return $this->transactions->run(function () use (
            $request,
            $operation,
            $revision,
            $actor,
            $context,
            $document,
            $pdf,
            $routing,
            $reviewToken,
        ): array {
            $this->lock($request, $routing);
            $this->authorize($actor);
            $existing = $this->records->document($operation);
            if ($existing !== null) {
                return $this->replay($existing, $request, $actor, $revision);
            }
            $current = $this->context($request);
            $this->check($this->revision($current), $revision);
            $this->reviews->validate(
                $reviewToken,
                "transcript.review",
                "prepare",
                $request,
                $actor,
                $operation,
                $revision,
            );
            $version = $current["request_version"];
            $this->records->addDocument($operation, [
                "request_id" => $request->value,
                "actor_id" => $actor->value,
                "prepared_version" => $version + 1,
                "created_at" => $document["issued_at"],
                "pdf_hash" => hash("sha256", $pdf),
                "encrypted_content" => $this->cipher->seal(
                    json_encode(
                        [
                            "revision" => $revision,
                            "academic_revision" => $this->revision($context["snapshot"]),
                            "document" => $document,
                            "pdf" => base64_encode($pdf),
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ]);
            $this->records->status($request, "ready", $version);
            $this->audit->record($actor, "transcript.document_prepared", $operation);
            $after = $this->context($request);
            if (
                $after["request_version"] !== $version + 1 ||
                $this->records->request($request)["status"] !== "ready"
            ) {
                throw new ConcurrentModificationException(
                    "The transcript request changed during confirmation.",
                );
            }
            $after["request_version"] = $version;
            $this->check($this->revision($after), $revision);
            $this->reviews->validate(
                $reviewToken,
                "transcript.review",
                "prepare",
                $request,
                $actor,
                $operation,
                $revision,
            );
            $this->authorize($actor);
            return $this->describe(
                $this->records->document($operation) ??
                    throw new RecordNotFoundException("Prepared document not found."),
            );
        });
    }

    /** @return array<string, mixed> */
    public function current(EntityId $request): array
    {
        $actor = $this->authorize();
        $record = $this->records->request($request);
        $latest = $this->records->latest($request);
        $this->authorize($actor);
        return [
            "request_id" => $request->value,
            "status" => $record["status"],
            "version" => (int) $record["version"],
            "document" => $latest === null ? null : $this->describe($latest),
        ];
    }

    /** @return array<string, mixed> */
    public function view(EntityId $request, EntityId $document): array
    {
        $actor = $this->authorize();
        $routing = $this->routing($request);
        $verificationFailed = false;
        $read = function (bool $verify) use (
            $request,
            $document,
            $actor,
            $routing,
            &$verificationFailed,
        ): array {
            $this->lock($request, $routing);
            $this->authorize($actor);
            $row = $this->ownedDocument($request, $document);
            $payload = $this->payload($row);
            $academicCheck = "unavailable";
            $academicChanged = null;
            if ($verify) {
                try {
                    $current = $this->snapshot($routing["student"]);
                } catch (ValidationException $error) {
                    $verificationFailed = true;
                    throw $error;
                }
                $academicCheck = $current["academic_verification"]["status"];
                $academicChanged = !hash_equals($payload["academic_revision"], $this->revision($current));
            }
            $delivery = $this->records->documentDelivery($document);
            $result = $this->describe($row) + [
                "snapshot" => $payload["document"],
                "academic_check" => $academicCheck,
                "academic_changed" => $academicChanged,
                "is_latest" => ($this->records->latest($request)["id"] ?? null) === $document->value,
                "delivery" => $delivery === null ? null : $this->payload($delivery)["request"],
            ];
            $this->audit->record($actor, "transcript.document_read", $document);
            $this->authorize($actor);
            return $result;
        };
        try {
            return $this->transactions->run(static fn(): array => $read(true));
        } catch (ValidationException $error) {
            if (!$verificationFailed) {
                throw $error;
            }
            // Failed nested journal reads must fully roll back before the independent original-document
            // read. Never clear a rollback-only transaction or treat failed verification as unchanged.
            return $this->transactions->run(static fn(): array => $read(false));
        }
    }

    public function pdf(EntityId $request, EntityId $document): string
    {
        $actor = $this->authorize();
        $row = $this->ownedDocument($request, $document);
        $bytes = base64_decode($this->payload($row)["pdf"], true);
        if ($bytes === false || !hash_equals($row["pdf_hash"], hash("sha256", $bytes))) {
            throw new \RuntimeException("The stored transcript document failed verification.");
        }
        $this->audit->record($actor, "transcript.document_downloaded", $document);
        $this->authorize($actor);
        return $bytes;
    }

    public function html(EntityId $request, EntityId $document): string
    {
        $actor = $this->authorize();
        $row = $this->ownedDocument($request, $document);
        $snapshot = $this->payload($row)["document"];
        $this->audit->record($actor, "transcript.document_read", $document);
        $this->authorize($actor);
        // The accessible companion is the protected original, not a new academic verification/release.
        $html = $this->renderer->html($snapshot);
        $this->authorize($actor);
        return $html;
    }

    /** Review evidence of actual delivery; this does not send the document.
     * @return array<string,mixed> */
    public function reviewDelivery(
        EntityId $request,
        EntityId $document,
        int $version,
        DateTimeImmutable $on,
        #[\SensitiveParameter] string $evidence,
    ): array {
        $actor = $this->authorize();
        $input = $this->deliveryInput($request, $document, $version, $on, $evidence);
        $routing = $this->routing($request);
        return $this->transactions->run(function () use (
            $request,
            $document,
            $version,
            $input,
            $routing,
            $actor,
        ): array {
            $this->lock($request, $routing);
            $this->authorize($actor);
            $context = $this->deliveryContext($request, $document, $input);
            $this->audit->record($actor, "transcript.delivery_reviewed", $document);
            $this->authorize($actor);
            $operation = EntityId::generate();
            return [
                "operation_id" => $operation->value,
                "delivery" => $input,
                "review_token" => $this->reviews->issue(
                    "transcript.review",
                    "deliver",
                    $request,
                    $actor,
                    $operation,
                    $this->revision($context),
                ),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function deliver(
        EntityId $request,
        EntityId $document,
        EntityId $operation,
        int $version,
        DateTimeImmutable $on,
        #[\SensitiveParameter] string $evidence,
        #[\SensitiveParameter] string $reviewToken,
    ): array {
        $actor = $this->authorize();
        $input = $this->deliveryInput($request, $document, $version, $on, $evidence);
        $routing = $this->routing($request);
        return $this->transactions->run(function () use (
            $request,
            $document,
            $operation,
            $actor,
            $input,
            $routing,
            $version,
            $reviewToken,
        ): array {
            $this->lock($request, $routing);
            $this->authorize($actor);
            $this->ownedDocument($request, $document);
            $previous = $this->records->delivery($operation);
            if ($previous !== null) {
                $data = $this->payload($previous);
                if (
                    $previous["document_id"] !== $document->value ||
                    $previous["actor_id"] !== $actor->value ||
                    $data["request"] !== $input
                ) {
                    throw new ConcurrentModificationException(
                        "The delivery operation belongs to another request.",
                    );
                }
                $this->authorize($actor);
                return $data["result"];
            }
            $context = $this->deliveryContext($request, $document, $input);
            $revision = $this->revision($context);
            $this->reviews->validate(
                $reviewToken,
                "transcript.review",
                "deliver",
                $request,
                $actor,
                $operation,
                $revision,
            );
            $result = [
                "request_id" => $request->value,
                "document_id" => $document->value,
                "operation_id" => $operation->value,
                "status" => "fulfilled",
                "delivered_at" => $input["delivered_at"],
            ];
            $this->records->addDelivery($operation, [
                "document_id" => $document->value,
                "actor_id" => $actor->value,
                "created_at" => $this->now(),
                "encrypted_content" => $this->cipher->seal(
                    json_encode(
                        [
                            "request" => $input,
                            "result" => $result,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ]);
            $this->records->status($request, "fulfilled", $version);
            $this->audit->record($actor, "transcript.delivery_recorded", $document);
            $this->check(
                $this->revision($this->deliveryContext($request, $document, $input, completed: true)),
                $revision,
            );
            $this->reviews->validate(
                $reviewToken,
                "transcript.review",
                "deliver",
                $request,
                $actor,
                $operation,
                $revision,
            );
            $this->authorize($actor);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function deliveryInput(
        EntityId $request,
        EntityId $document,
        int $version,
        DateTimeImmutable $on,
        #[\SensitiveParameter] string $evidence,
    ): array {
        $reason = trim($evidence);
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            $reason === "" ||
            mb_strlen($reason) > 2000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) ||
            $on > $this->clock->now() ||
            $version < 1
        ) {
            throw new ValidationException([
                [
                    "field" => "evidence",
                    "message" =>
                        "Record valid delivery evidence (up to 2,000 characters) and a timestamp no later than now.",
                ],
            ]);
        }
        return [
            "request_id" => $request->value,
            "document_id" => $document->value,
            "version" => $version,
            "delivered_at" => $on->setTimezone(new DateTimeZone("UTC"))->format(DATE_ATOM),
            "evidence" => $reason,
        ];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function deliveryContext(
        EntityId $request,
        EntityId $document,
        #[\SensitiveParameter] array $input,
        bool $completed = false,
    ): array {
        $row = $this->ownedDocument($request, $document);
        $current = $this->records->request($request);
        $snapshot = $this->snapshot(new EntityId($current["student_id"]));
        $academic = $this->revision($snapshot);
        if (
            $current["status"] !== ($completed ? "fulfilled" : "ready") ||
            (int) $current["version"] !== $input["version"] + (int) $completed ||
            ($this->records->latest($request)["id"] ?? null) !== $document->value ||
            !hash_equals($this->payload($row)["academic_revision"], $academic)
        ) {
            throw new ConcurrentModificationException(
                "Review the latest academic record and prepare an updated document before recording delivery.",
            );
        }
        return [
            "input" => $input,
            "student_id" => $current["student_id"],
            "person_id" => $this->admissions->student(new EntityId($current["student_id"]))->personId->value,
            "academic_revision" => $academic,
            "pdf_hash" => $row["pdf_hash"],
            "delivery" => $current["delivery"],
            "recipient_revision" => hash("sha256", $current["encrypted_recipient"]),
        ];
    }

    /** @return array{student: EntityId, person: EntityId} */
    private function routing(EntityId $request): array
    {
        $student = new EntityId($this->records->request($request)["student_id"]);
        return [
            "student" => $student,
            "person" => $this->admissions->student($student)->personId,
        ];
    }

    /** @param array{student: EntityId, person: EntityId} $routing */
    private function lock(EntityId $request, array $routing): void
    {
        $this->records->lockStudent($routing["student"]);
        $this->records->lockPerson($routing["person"]);
        $this->records->lockRequest($request);
        $this->authorize();
        if (
            $this->records->request($request)["student_id"] !== $routing["student"]->value ||
            $this->admissions->student($routing["student"])->personId->value !== $routing["person"]->value
        ) {
            throw new ConcurrentModificationException(
                "The transcript student identity changed. Review again.",
            );
        }
    }

    /** @return array<string, mixed> */
    private function context(EntityId $request): array
    {
        $record = $this->records->request($request);
        if (!in_array($record["status"], ["pending", "ready"], true)) {
            throw new ValidationException([
                [
                    "field" => "request",
                    "message" => "Only pending or ready requests can prepare a document.",
                ],
            ]);
        }
        return [
            "request_id" => $request->value,
            "request_version" => (int) $record["version"],
            "student_id" => $record["student_id"],
            "person_id" => $this->admissions->student(new EntityId($record["student_id"]))->personId->value,
            "delivery" => $record["delivery"],
            "recipient_revision" => hash("sha256", $record["encrypted_recipient"]),
            "snapshot" => $this->snapshot(new EntityId($record["student_id"])),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(EntityId $student): array
    {
        $s = $this->admissions->student($student);
        $person = $this->admissions->person($s->personId);
        $rows = $this->records->creditLabels($student);
        if (count($rows) > 500) {
            throw new ValidationException([
                [
                    "field" => "credits",
                    "message" =>
                        "This academic record exceeds 500 rows and needs the institutional bulk document process. " .
                        "No partial transcript was generated.",
                ],
            ]);
        }
        try {
            $verified = $this->academicInputs->verify($student, new HistoryReadBudget());
        } catch (CorruptHistoryException) {
            throw new ValidationException([
                [
                    "field" => "academic_history",
                    "message" =>
                        "The current academic record does not match its recorded history. " .
                        "Ask the records office to review it before preparing or releasing a transcript.",
                ],
            ]);
        } catch (HistoryReadLimitException) {
            throw new ValidationException([
                [
                    "field" => "academic_history",
                    "message" =>
                        "This record exceeds the bounded academic-history verification limit " .
                        "and needs a separate institutional review. No partial transcript was prepared.",
                ],
            ]);
        }
        $workById = array_column($verified->items, null, "id");
        $references = array_column($verified->references, null, "credit_id");
        if (count($workById) !== count($rows)) {
            throw new ValidationException([
                [
                    "field" => "academic_history",
                    "message" =>
                        "The transcript inventory changed. Review the academic record again " .
                        "before preparing a document.",
                ],
            ]);
        }
        foreach ($rows as &$row) {
            $work = $workById[$row["id"]] ?? null;
            $reference = $references[$row["id"]] ?? null;
            if ($work === null || $reference === null) {
                throw new ValidationException([
                    [
                        "field" => "academic_history",
                        "message" =>
                            "The transcript inventory changed. Review the academic record again " .
                            "before preparing a document.",
                    ],
                ]);
            }
            if (
                $work["grade"] === null &&
                ($work["grade_recorded"] ||
                    $work["status"] === "W" ||
                    $work["completed_milli"] > 0 ||
                    $work["quality_points_milli"] > 0)
            ) {
                throw new ValidationException([
                    [
                        "field" => "academic_history",
                        "message" =>
                            "A recorded grade has no verified grading definition. " .
                            "Ask the records office to review its grade history " .
                            "before preparing or releasing a transcript.",
                    ],
                ]);
            }
            // Term labels remain explicitly current-calendar fallbacks where the source retained none.
            // Academic titles, quantities, grades and dates come exclusively from verified ledger facts.
            $row = [
                "id" => $work["id"],
                "course_code" => $work["course_code"],
                "course_title" => $work["course_title"],
                "credit_type" => $work["credit_type"],
                "status" => $work["status"],
                "version" => $row["version"],
                "term_code" => $work["term_code"] ?? $row["term_code"],
                "term_label_retained" => $work["term_label_retained"],
                "term_starts_on" => $row["term_starts_on"],
                "attempted_milli" => $work["attempted_milli"],
                "completed_milli" => $work["completed_milli"],
                "grade_points_milli" => $work["quality_points_milli"],
                "grade_code" => $work["status"] === "V" ? "Voided" : $work["grade"]["code"] ?? null,
                "counts_gpa" => $work["grade"]["counts_gpa"] ?? false,
                "academic_on" => $work["academic_on"],
                "completed_on" => $work["completed_on"],
                "history_recorded" => $work["history_recorded"],
                "earlier_history_unknown" => $reference["earlier_history_unknown"],
                "academic_version" => $reference["academic_version"],
                "registration_version" => $reference["registration_version"],
                "course_version_id" => $work["course_version_id"],
                "section_version_id" => $work["section_version_id"],
            ];
        }
        unset($row);
        $attempted = $earned = $points = $gpaCredits = 0;
        foreach ($rows as $row) {
            $attempted += $row["attempted_milli"];
            $earned += $row["completed_milli"];
            if ($row["counts_gpa"] && $row["grade_code"] !== null) {
                $gpaCredits += $row["attempted_milli"];
                $points += $row["grade_points_milli"];
            }
        }
        return [
            "issuer" => [
                "name" => $this->issuer->name,
                "address" => $this->issuer->address,
            ],
            "student" => [
                "name" => trim($person->givenName . " " . $person->familyName),
                "institutional_id" => $person->institutionalId->value,
            ],
            "program" => $this->students->program($s->programId),
            "catalog" => $this->students->primaryCatalog($student),
            "credits" => $rows,
            "academic_verification" => [
                "status" => $verified->status->value,
                "references_digest" => $this->revision($verified->references),
            ],
            "totals" => [
                "attempted_milli" => $attempted,
                "completed_milli" => $earned,
                "gpa_milli" => $gpaCredits === 0 ? null : intdiv($points * 1000, $gpaCredits),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function ownedDocument(EntityId $request, EntityId $id): array
    {
        $row = $this->records->document($id);
        if ($row === null || $row["request_id"] !== $request->value) {
            throw new RecordNotFoundException("Transcript document not found.");
        }
        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(
        #[\SensitiveParameter] array $row,
        EntityId $request,
        EntityId $actor,
        string $revision,
    ): array {
        if (
            $row["request_id"] !== $request->value ||
            $row["actor_id"] !== $actor->value ||
            $this->payload($row)["revision"] !== $revision
        ) {
            throw new ConcurrentModificationException(
                "The preparation operation belongs to another request.",
            );
        }
        $this->authorize($actor);
        return $this->describe($row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function describe(#[\SensitiveParameter] array $row): array
    {
        return [
            "id" => $row["id"],
            "request_id" => $row["request_id"],
            "created_at" => $row["created_at"],
            "prepared_version" => (int) $row["prepared_version"],
            "pdf_hash" => $row["pdf_hash"],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(#[\SensitiveParameter] array $row): array
    {
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $data */
    private function revision(array $data): string
    {
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function check(string $current, string $expected): void
    {
        if (!hash_equals($current, $expected)) {
            throw new ConcurrentModificationException("The transcript preparation changed. Review it again.");
        }
    }

    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(DATE_ATOM);
    }
}
