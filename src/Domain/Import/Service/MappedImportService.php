<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Import\ValueObject\CatalogImportRow;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Communications\Service\MessageCipher;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PDOException;

/** Reviewed draft imports and external identity reconciliation; publication remains a separate decision. */
final readonly class MappedImportService
{
    public function __construct(
        private CatalogCsvMapper $mapper,
        private CatalogImportRepository $repository,
        private CatalogWorkflowRepository $operations,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @param array<string,int> $mapping
     * @param array<array-key,mixed> $choices
     * @return array<string,mixed> */
    public function preview(
        string $source,
        #[\SensitiveParameter] string $csv,
        array $mapping,
        string $delimiter = ",",
        array $choices = [],
        CatalogImportTarget $target = CatalogImportTarget::Course,
    ): array {
        $actor = $this->actor();
        $this->source($source);
        $source = strtolower($source);
        $prepared = $this->mapper->prepare($csv, $mapping, $delimiter, $target);
        $rows = $prepared["rows"];
        unset($prepared["rows"]);
        if (!$prepared["ready"]) {
            $this->actor($actor);
            return $prepared + ["plan" => [], "review_token" => null];
        }
        $selected = $this->choices($rows, $choices);
        $plan = $this->plan($rows, $this->repository->inspect($source, $rows), $selected);
        $prepared["ready"] = $plan["summary"]["match"] === 0 && $plan["summary"]["conflict"] === 0;
        $token = null;
        if ($prepared["ready"]) {
            $now = $this->clock->now()->getTimestamp();
            $token = $this->cipher->seal(
                json_encode(
                    [
                        "purpose" => "catalog." . $target->value . "-import",
                        "actor_id" => $actor->value,
                        "operation_id" => EntityId::generate()->value,
                        "source" => $source,
                        "request_hash" => $this->hash([$source, $prepared["fingerprint"], $selected]),
                        "plan_hash" => $this->hash($plan),
                        "issued_at" => $now,
                        "expires_at" => $now + 1200,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            );
        }
        $this->actor($actor);
        return $prepared + $plan + ["review_token" => $token, "review_minutes" => 20];
    }

    /** @param array<string,int> $mapping
     * @param array<array-key,mixed> $choices
     * @return array<string,int|string> */
    public function commit(
        string $source,
        #[\SensitiveParameter] string $csv,
        array $mapping,
        #[\SensitiveParameter] string $token,
        string $delimiter = ",",
        array $choices = [],
        CatalogImportTarget $target = CatalogImportTarget::Course,
    ): array {
        $actor = $this->actor();
        $this->source($source);
        $source = strtolower($source);
        $prepared = $this->mapper->prepare($csv, $mapping, $delimiter, $target);
        if (!$prepared["ready"]) {
            throw new ValidationException($prepared["errors"]);
        }
        $rows = $prepared["rows"];
        $selected = $this->choices($rows, $choices);
        $request = $this->hash([$source, $prepared["fingerprint"], $selected]);
        $ticket = $this->ticket($token, $source, $actor, $request, $target);
        $operation = new EntityId($ticket["operation_id"]);
        return $this->transactions->run(function () use (
            $source,
            $rows,
            $selected,
            $request,
            $ticket,
            $operation,
            $actor,
            $target,
        ): array {
            $this->actor($actor);
            $old = $this->operations->operation($operation);
            if ($old !== null) {
                $receipt = json_decode(
                    $this->cipher->open($old["encrypted_content"]),
                    true,
                    8,
                    JSON_THROW_ON_ERROR,
                );
                if (
                    $old["actor_id"] !== $actor->value ||
                    $old["kind"] !== $target->value . "_import" ||
                    ($receipt["request_hash"] ?? null) !== $request
                ) {
                    throw new ConcurrentModificationException(
                        "This receipt belongs to another catalog request.",
                    );
                }
                $this->actor($actor);
                return $receipt["result"];
            }
            $this->expiry($ticket);
            $snapshot = $this->repository->inspect($source, $rows);
            $ids = [];
            foreach ($snapshot["records"] as $matchesForCode) {
                foreach ($matchesForCode as $record) {
                    $ids[] = $record["id"];
                }
            }
            // A locking reread sees catalog edits committed during a wait, even under repeatable-read isolation.
            $withReferences = false;
            foreach ($rows as $row) {
                $withReferences = $withReferences || $row->references !== [];
            }
            $snapshot["records"] = [];
            foreach ($this->repository->lockRecords($ids, $withReferences, $target) as $record) {
                $snapshot["records"][mb_strtolower($record["code"])][] = $record;
            }
            $references = [];
            foreach ($snapshot["references"] as $matches) {
                foreach ($matches as $reference) {
                    $references[] = $reference;
                }
            }
            if ($references !== []) {
                $snapshot["references"] = [];
                foreach ($this->repository->lockReferences($references) as $reference) {
                    $key = $reference["kind"] . ":" . strtoupper($reference["code"]);
                    $snapshot["references"][$key][] = $reference;
                }
            }
            $plan = $this->plan($rows, $snapshot, $selected);
            if (!hash_equals($ticket["plan_hash"], $this->hash($plan))) {
                throw new ConcurrentModificationException(
                    "The reviewed catalog matches changed. Review the import again.",
                );
            }
            if ($plan["summary"]["match"] !== 0 || $plan["summary"]["conflict"] !== 0) {
                throw new ValidationException([
                    ["field" => "file", "message" => "Resolve every import row first."],
                ]);
            }
            $written = [];
            foreach ($rows as $index => $row) {
                $action = $plan["plan"][$index];
                try {
                    if ($action["action"] === "create") {
                        $id = $this->repository->create($source, $row, $action["references"]);
                        $written[] = [$id, $row, $action];
                        $this->audit->record($actor, $target->value . ".imported", $id);
                    } elseif ($action["action"] === "link") {
                        $id = new EntityId($action[$target->idField()]);
                        $this->repository->link($source, $row, $id);
                        $written[] = [$id, $row, $action];
                        $this->audit->record($actor, $target->value . ".import_linked", $id);
                    }
                } catch (PDOException $error) {
                    if (!in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                        throw $error;
                    }
                    throw new ValidationException([
                        [
                            "field" => "row_" . $row->row,
                            "message" =>
                                "Row " .
                                $row->row .
                                ": its code or source ID changed. Review the import again.",
                        ],
                    ]);
                }
            }
            $result = [
                "operation_id" => $operation->value,
                "imported" => $plan["summary"]["create"],
                "linked" => $plan["summary"]["link"],
                "skipped" => $plan["summary"]["skip"],
                "omitted" => $plan["summary"]["omitted"],
                "total" => count($rows),
            ];
            $this->operations->record(
                $operation,
                $actor,
                $target->value . "_import",
                $this->cipher->seal(
                    json_encode(
                        [
                            "request_hash" => $request,
                            "result" => $result,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                $this->clock->now()->format(DATE_ATOM),
            );
            $this->audit->record($actor, "catalog.import_completed", $operation);
            $this->actor($actor);
            $this->expiry($ticket);
            $this->verifyWritten($source, $written, $references, $target);
            $this->actor($actor);
            $this->expiry($ticket);
            return $result;
        });
    }

    /** Recheck only this confirmation's writes; prior import facts are never silently reapplied. */
    private function verifyWritten(
        string $source,
        array $written,
        array $references,
        CatalogImportTarget $target,
    ): void {
        if ($written === []) {
            return;
        }
        $ids = array_map(static fn(array $item): string => $item[0]->value, $written);
        $current = array_column($this->repository->lockRecords($ids, true, $target), null, "id");
        $available = [];
        foreach ($this->repository->lockReferences($references) as $reference) {
            $available[$reference["kind"] . ":" . strtoupper($reference["code"])][] = $reference;
        }
        $mappings = $this->repository->inspect($source, array_column($written, 1))["mappings"];
        foreach ($written as [$id, $row, $action]) {
            $record = $current[$id->value] ?? null;
            $mapping = $mappings["external:" . strtolower($row->externalId)] ?? null;
            $resolved = $this->resolveReferences($row, $available);
            $expectedCode =
            $action["action"] === "create" ? $row->code : $action["matched_" . $target->value]["code"];
            if (
                $record === null ||
                $record["code"] !== $expectedCode ||
                $record["title"] !== $row->title ||
                $mapping === null ||
                ($mapping[$target->idField()] ?? null) !== $id->value ||
                ($mapping["external_id"] ?? null) !== $row->externalId ||
                ($mapping["content_hash"] ?? null) !== $row->fingerprint() ||
                $resolved["errors"] !== [] ||
                $resolved["references"] !== $action["references"]
            ) {
                throw new ConcurrentModificationException(
                    "The imported catalog facts changed before completion. Review again.",
                );
            }
            if (
                $target === CatalogImportTarget::Course &&
                ((int) $record["credits_milli"] !== $row->creditsMilli ||
                    (bool) $record["active"] !==
                        ($action["action"] === "create" ? false : $action["matched_course"]["active"]))
            ) {
                throw new ConcurrentModificationException(
                    "The imported course draft changed before completion.",
                );
            }
            if (
                $target === CatalogImportTarget::Program &&
                ($row->descriptionMapped || $action["action"] === "create") &&
                ($record["description"] ?? null) !== $row->description
            ) {
                throw new ConcurrentModificationException(
                    "The imported program description changed before completion.",
                );
            }
            if (
                $target->isReference() &&
                (!(bool) $record["active"] ||
                    ($target->classification() !== null && ($record["kind"] ?? null) !== $target->value))
            ) {
                throw new ConcurrentModificationException(
                    "The imported reference changed before completion.",
                );
            }
            $referenceKinds = $action["action"] === "create" ? $target->references() : $row->references;
            foreach (array_keys($referenceKinds) as $kind) {
                if (($record[$kind . "_id"] ?? null) !== ($action["references"][$kind]["id"] ?? null)) {
                    throw new ConcurrentModificationException(
                        "The imported classifications changed before completion.",
                    );
                }
            }
        }
    }

    /** @param list<CatalogImportRow> $rows
     * @param array{
     *     mappings:array<string,array<string,mixed>>,records:array<string,list<array<string,mixed>>>,
     *     references:array<string,list<array<string,mixed>>>
     * } $snapshot
     * @param array<string,array{action:string,course_id:?string}> $choices
     * @return array{plan:list<array<string,mixed>>,summary:array<string,int>} */
    private function plan(array $rows, array $snapshot, array $choices): array
    {
        $plan = [];
        $counts = array_fill_keys(["create", "link", "skip", "omitted", "match", "conflict"], 0);
        $newCodes = [];
        foreach ($rows as $row) {
            $target = $row->target;
            $idField = $target->idField();
            $label = str_replace("_", " ", $target->value);
            $key = "external:" . $row->externalId;
            $choice = $choices[$key] ?? null;
            $mapping = $snapshot["mappings"]["external:" . strtolower($row->externalId)] ?? null;
            $matches = $snapshot["records"][mb_strtolower($row->code)] ?? [];
            $record = count($matches) === 1 ? $matches[0] : null;
            $resolved = ["references" => [], "errors" => []];
            if ($mapping === null && ($choice["action"] ?? null) !== "skip") {
                $resolved = $this->resolveReferences($row, $snapshot["references"]);
            }
            $sameCore =
            $record !== null &&
            $record["title"] === $row->title &&
            ($target === CatalogImportTarget::Course
            ? (int) $record["credits_milli"] === $row->creditsMilli
            : !$row->descriptionMapped || ($record["description"] ?? null) === $row->description);
            $same =
            $sameCore &&
            $resolved["errors"] === [] &&
            (!$target->isReference() || (bool) $record["active"]);
            foreach ($resolved["references"] as $kind => $reference) {
                $same = $same && ($record[$kind . "_id"] ?? null) === $reference["id"];
            }
            $recordId = null;
            $matched = null;
            if (($choice["action"] ?? null) === "skip") {
                $action = "omitted";
                $message = "Left out of this import.";
            } elseif (
                $mapping !== null &&
                (($mapping["ambiguous"] ?? false) || $mapping["external_id"] !== $row->externalId)
            ) {
                $action = "conflict";
                $message =
                "Recorded source IDs differ only by letter case. " .
                "Resolve the source identity or leave this row out.";
            } elseif ($mapping !== null) {
                $action = hash_equals($mapping["content_hash"], $row->fingerprint()) ? "skip" : "conflict";
                $recordId = $mapping[$idField];
                $message =
                $action === "skip"
                ? "Previously imported; the existing " . $label . " is left unchanged."
                : "This source ID already identifies different " .
                $label .
                " data. Leave it out or correct the source file.";
                if ($choice !== null) {
                    $action = "conflict";
                    $message = "This source ID is already mapped. Remove the link choice and review again.";
                }
            } elseif (count($matches) > 1) {
                $action = "conflict";
                $message =
                "Several catalog " .
                $label .
                "s use this code. Resolve the ambiguity before linking.";
            } elseif ($record !== null) {
                $recordId = $record["id"];
                $matched =
                ["id" => $recordId, "code" => $record["code"], "title" => $record["title"]] +
                ($target === CatalogImportTarget::Course
                ? [
                            "credits_milli" => (int) $record["credits_milli"],
                            "active" => (bool) $record["active"],
                        ]
                : ($target->isReference()
                            ? ["active" => (bool) $record["active"]]
                            : ["description" => $record["description"] ?? null]));
                $action = $same ? ($choice === null ? "match" : "link") : "conflict";
                $message =
                $target->isReference() && !(bool) $record["active"]
                ? "This reference is retired. Review it in catalog setup or leave this row out. " .
                "Imports never reactivate references."
                : ($same
                            ? "An existing " .
                                $label .
                                " matches. Approve linking its source ID, or leave this row out."
                            : ($sameCore
                                ? "The existing " .
                                    $label .
                                    " has different classifications. Use the catalog editor or leave it out."
                                : ($target === CatalogImportTarget::Course
                                    ? "This code already has a different title or credits. " .
                                        "Existing details will not be overwritten."
                                    : ($target->isReference()
                                        ? "This code already has a different name. " .
                                            "Existing entries will not be overwritten."
                                        : "This code already has a different title or mapped description. " .
                                            "Existing details will not be overwritten."))));
                if (
                    $choice !== null &&
                    ($choice["action"] !== "link" || $choice[$idField] !== $recordId || !$same)
                ) {
                    $action = "conflict";
                    $message =
                    "The selected link does not match this row. Review it again or leave the row out.";
                }
            } elseif ($choice !== null) {
                $action = "conflict";
                $message =
                "The selected " .
                $label .
                " no longer matches. Remove the link choice and review again.";
            } elseif (isset($newCodes[mb_strtolower($row->code)])) {
                $action = "conflict";
                $message = "Another included row uses this code. Leave one out or correct the source file.";
            } else {
                $action = "create";
                $newCodes[mb_strtolower($row->code)] = true;
                $message =
                $target === CatalogImportTarget::Course
                ? "Create an inactive course draft; publication and activation remain separate."
                : ($target->isReference()
                            ? "Create an active catalog reference; existing publications remain unchanged."
                            : "Create a working program definition; academic-year publication remains separate.");
            }
            if ($resolved["errors"] !== []) {
                $action = "conflict";
                $message =
                "Resolve the catalog classifications or leave this row out. " .
                implode(" ", $resolved["errors"]);
            }
            ++$counts[$action];
            $plan[] =
            [
                    "row" => $row->row,
                    "external_id" => $row->externalId,
                    "code" => $row->code,
                    "title" => $row->title,
                    "credits_milli" => $row->creditsMilli,
                    "action" => $action,
                    $idField => $recordId,
                    "matched_" . $target->value => $matched,
                    "message" => $message,
                    "source_references" => $row->references,
                    "references" => $resolved["references"],
                    "reference_errors" => $resolved["errors"],
                ] +
            ($target === CatalogImportTarget::Program
            ? ["description" => $row->description, "description_mapped" => $row->descriptionMapped]
            : []);
        }
        return ["plan" => $plan, "summary" => $counts];
    }

    /** @param array<string,list<array<string,mixed>>> $available
     * @return array{references:array<string,array{id:string,code:string,title:string}>,errors:list<string>} */
    private function resolveReferences(CatalogImportRow $row, array $available): array
    {
        $references = [];
        $errors = [];
        foreach ($row->references as $kind => $code) {
            $matches = $available[$kind . ":" . $code] ?? [];
            $label = $row->target->references()[$kind];
            if (count($matches) !== 1) {
                $errors[] =
                $label . " " . $code . (count($matches) > 1 ? " is ambiguous." : " was not found.");
                continue;
            }
            $reference = $matches[0];
            if (!(bool) $reference["active"]) {
                $errors[] = $label . " " . $code . " is retired.";
                continue;
            }
            try {
                if (
                    ($reference["kind"] ?? null) !== $kind ||
                    !is_string($reference["id"] ?? null) ||
                    !is_string($reference["code"] ?? null) ||
                    !is_string($reference["title"] ?? null)
                ) {
                    throw new InvalidArgumentException();
                }
                $data = [
                    "id" => $reference["id"],
                    "code" => $reference["code"],
                    "title" => $reference["title"],
                ];
                $references[$kind] = ($kind === "subject"
                    ? SubjectSnapshot::fromArray($data)
                    : ClassificationSnapshot::fromArray($data)
                )->describe();
            } catch (InvalidArgumentException) {
                $errors[] = $label . " " . $code . " needs catalog review.";
            }
        }
        return ["references" => $references, "errors" => $errors];
    }

    /** @param list<CatalogImportRow> $rows
     * @param array<array-key,mixed> $choices
     * @return array<string,array{action:string,course_id:?string}> */
    private function choices(array $rows, array $choices): array
    {
        if (!array_is_list($choices) || count($choices) > 1000) {
            throw new InvalidArgumentException("Choose a bounded list of row actions.");
        }
        $known = [];
        foreach ($rows as $row) {
            $known["external:" . $row->externalId] = $row->target->idField();
        }
        $selected = [];
        foreach ($choices as $choice) {
            if (
                !is_array($choice) ||
                count($choice) !== 3 ||
                !is_string($choice["external_id"] ?? null) ||
                !in_array($choice["action"] ?? null, ["link", "skip"], true) ||
                !isset($known["external:" . $choice["external_id"]]) ||
                !array_key_exists($known["external:" . $choice["external_id"]], $choice)
            ) {
                throw new InvalidArgumentException("A row choice is invalid.");
            }
            $key = "external:" . $choice["external_id"];
            $idField = $known[$key];
            if (
                !isset($known[$key]) ||
                isset($selected[$key]) ||
                ($choice["action"] === "skip" ? $choice[$idField] !== null : !is_string($choice[$idField]))
            ) {
                throw new InvalidArgumentException("Choose each known source row once.");
            }
            if ($choice["action"] === "link") {
                new EntityId($choice[$idField]);
            }
            $selected[$key] = ["action" => $choice["action"], $idField => $choice[$idField]];
        }
        ksort($selected, SORT_STRING);
        return $selected;
    }

    private function source(string $source): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $source) !== 1) {
            throw new InvalidArgumentException(
                "Use a stable source name of up to 80 letters, numbers, dots or hyphens.",
            );
        }
    }

    private function actor(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:import:manage");
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException("The import actor changed.");
        }
        return $actor;
    }

    private function hash(mixed $value): string
    {
        return hash("sha256", json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function ticket(
        #[\SensitiveParameter] string $token,
        string $source,
        EntityId $actor,
        string $request,
        CatalogImportTarget $target,
    ): array {
        try {
            if (strlen($token) > 100000) {
                throw new InvalidArgumentException();
            }
            $ticket = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($ticket) ||
                count($ticket) !== 8 ||
                ($ticket["purpose"] ?? null) !== "catalog." . $target->value . "-import" ||
                ($ticket["source"] ?? null) !== $source ||
                ($ticket["actor_id"] ?? null) !== $actor->value ||
                ($ticket["request_hash"] ?? null) !== $request ||
                !is_string($ticket["operation_id"] ?? null) ||
                !is_string($ticket["plan_hash"] ?? null) ||
                !is_int($ticket["issued_at"] ?? null) ||
                !is_int($ticket["expires_at"] ?? null) ||
                $ticket["expires_at"] - $ticket["issued_at"] !== 1200
            ) {
                throw new InvalidArgumentException();
            }
            new EntityId($ticket["operation_id"]);
            return $ticket;
        } catch (\Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this import again."],
            ]);
        }
    }

    /** @param array<string,mixed> $ticket */
    private function expiry(array $ticket): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($now < $ticket["issued_at"] || $now >= $ticket["expires_at"]) {
            throw new ConcurrentModificationException(
                "The import review expired. Review the file and choices again.",
            );
        }
    }
}
