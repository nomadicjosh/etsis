<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
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
    ) {
    }

    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission("sis:transcript:manage");
        $this->access->requirePermission("sis:student:read");
        return $actor;
    }

    /** @return array<string, mixed> */
    public function review(EntityId $request): array
    {
        $actor = $this->authorize();
        $routing = $this->routing($request);
        return $this->transactions->run(function () use ($request, $routing, $actor): array {
            $this->lock($request, $routing);
            $context = $this->context($request);
            $this->audit->record($actor, "transcript.preparation_reviewed", $request);
            return $context + ["revision" => $this->revision($context), "operation_id" => EntityId::generate()->value];
        });
    }

    /** @return array<string, mixed> */
    public function prepare(EntityId $request, EntityId $operation, string $revision, bool $releaseReviewed): array
    {
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
        $context = $this->review($request);
        unset($context["revision"], $context["operation_id"]);
        $this->check($this->revision($context), $revision);
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
        $routing = $this->routing($request);
        return $this->transactions->run(function () use (
            $request,
            $operation,
            $revision,
            $actor,
            $context,
            $document,
            $pdf,
            $routing,
        ): array {
            $this->lock($request, $routing);
            $existing = $this->records->document($operation);
            if ($existing !== null) {
                return $this->replay($existing, $request, $actor, $revision);
            }
            $current = $this->context($request);
            $this->check($this->revision($current), $revision);
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
            return $this->describe(
                $this->records->document($operation) ??
                    throw new RecordNotFoundException("Prepared document not found."),
            );
        });
    }

    /** @return array<string, mixed> */
    public function current(EntityId $request): array
    {
        $this->authorize();
        $record = $this->records->request($request);
        $latest = $this->records->latest($request);
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
        $row = $this->ownedDocument($request, $document);
        $payload = $this->payload($row);
        $current = $this->snapshot(new EntityId($this->records->request($request)["student_id"]));
        $delivery = $this->records->documentDelivery($document);
        $this->audit->record($actor, "transcript.document_read", $document);
        return $this->describe($row) + [
            "snapshot" => $payload["document"],
            "academic_changed" => !hash_equals($payload["academic_revision"], $this->revision($current)),
            "is_latest" => ($this->records->latest($request)["id"] ?? null) === $document->value,
            "delivery" => $delivery === null ? null : $this->payload($delivery)["request"],
        ];
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
        return $bytes;
    }

    public function html(EntityId $request, EntityId $document): string
    {
        return $this->renderer->html($this->view($request, $document)["snapshot"]);
    }

    /** @return array<string, mixed> */
    public function deliver(
        EntityId $request,
        EntityId $document,
        EntityId $operation,
        int $version,
        DateTimeImmutable $on,
        #[\SensitiveParameter] string $evidence,
    ): array {
        $actor = $this->authorize();
        $reason = trim($evidence);
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            $reason === "" ||
            mb_strlen($reason) > 2000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) ||
            $on > $this->clock->now()
        ) {
            throw new ValidationException([
                [
                    "field" => "evidence",
                    "message" =>
                        "Record valid delivery evidence (up to 2,000 characters) and a timestamp no later than now.",
                ],
            ]);
        }
        $input = [
            "request_id" => $request->value,
            "document_id" => $document->value,
            "version" => $version,
            "delivered_at" => $on->setTimezone(new DateTimeZone("UTC"))->format(DATE_ATOM),
            "evidence" => $reason,
        ];
        $routing = $this->routing($request);
        return $this->transactions->run(function () use (
            $request,
            $document,
            $operation,
            $actor,
            $input,
            $routing,
            $version,
        ): array {
            $this->lock($request, $routing);
            $row = $this->ownedDocument($request, $document);
            $previous = $this->records->delivery($operation);
            if ($previous !== null) {
                $data = $this->payload($previous);
                if (
                    $previous["document_id"] !== $document->value ||
                    $previous["actor_id"] !== $actor->value ||
                    $data["request"] !== $input
                ) {
                    throw new ConcurrentModificationException("The delivery operation belongs to another request.");
                }
                return $data["result"];
            }
            $current = $this->records->request($request);
            $snapshot = $this->snapshot(new EntityId($current["student_id"]));
            if (
                $current["status"] !== "ready" ||
                (int) $current["version"] !== $version ||
                ($this->records->latest($request)["id"] ?? null) !== $document->value ||
                !hash_equals($this->payload($row)["academic_revision"], $this->revision($snapshot))
            ) {
                throw new ConcurrentModificationException(
                    "Review the latest academic record and prepare an updated document before recording delivery.",
                );
            }
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
            return $result;
        });
    }

    /** @return array{student: EntityId, person: EntityId} */
    private function routing(EntityId $request): array
    {
        $student = new EntityId($this->records->request($request)["student_id"]);
        return ["student" => $student, "person" => $this->admissions->student($student)->personId];
    }

    /** @param array{student: EntityId, person: EntityId} $routing */
    private function lock(EntityId $request, array $routing): void
    {
        $this->records->lockStudent($routing["student"]);
        $this->records->lockPerson($routing["person"]);
        $this->records->lockRequest($request);
    }

    /** @return array<string, mixed> */
    private function context(EntityId $request): array
    {
        $record = $this->records->request($request);
        if (!in_array($record["status"], ["pending", "ready"], true)) {
            throw new ValidationException([
                ["field" => "request", "message" => "Only pending or ready requests can prepare a document."],
            ]);
        }
        return [
            "request_id" => $request->value,
            "request_version" => (int) $record["version"],
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
        $rows = $this->records->credits($student);
        if (count($rows) > 500) {
            throw new ValidationException([
                [
                    "field" => "credits",
                    "message" =>
                        "This academic record exceeds 500 rows and needs the institutional bulk document process. "
                        . "No partial transcript was generated.",
                ],
            ]);
        }
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
            "issuer" => ["name" => $this->issuer->name, "address" => $this->issuer->address],
            "student" => [
                "name" => trim($person->givenName . " " . $person->familyName),
                "institutional_id" => $person->institutionalId->value,
            ],
            "program" => $this->students->program($s->programId),
            "catalog" => $this->students->primaryCatalog($student),
            "credits" => $rows,
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
    private function replay(array $row, EntityId $request, EntityId $actor, string $revision): array
    {
        if (
            $row["request_id"] !== $request->value ||
            $row["actor_id"] !== $actor->value ||
            $this->payload($row)["revision"] !== $revision
        ) {
            throw new ConcurrentModificationException("The preparation operation belongs to another request.");
        }
        return $this->describe($row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function describe(array $row): array
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
