<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Import\Repository\PersonImportRepository;
use Domain\Import\ValueObject\PersonImportRow;
use Domain\Person\Service\PersonRegistrationService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PDOException;
use Throwable;

use function Codefy\Framework\Helpers\trans_html;

/** Create-only identity intake; use ordinary person/account creation and its protected history. */
final readonly class PersonImportService
{
    public function forAccess(StudentAccess $access): self
    {
        return new self(
            $this->mapper,
            $this->imports,
            $this->people->forAccess($access),
            $access,
            $this->transactions,
            $this->audit,
            $this->cipher,
            $this->clock,
        );
    }
    public function __construct(
        private PersonCsvMapper $mapper,
        private PersonImportRepository $imports,
        private PersonRegistrationService $people,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @param array<string,int> $mapping
     * @return array<string,mixed> */
    public function preview(
        #[\SensitiveParameter] string $csv,
        array $mapping = [],
        string $delimiter = ",",
        #[\SensitiveParameter] string $reason = "",
    ): array {
        $review = $this->analyze($csv, $mapping, $delimiter, $reason);
        if (count($review["rows"]) > 10) {
            throw new InvalidArgumentException(
                trans_html("Use at most ten people per processing batch."),
            );
        }
        if ($review["ready"]) {
            $review["expires_at"] = $this->clock->now()->getTimestamp() + 1200;
            $review["review_token"] = $this->cipher->seal(
                json_encode(
                    [
                        "kind" => "person-import-review",
                        "operation_id" => EntityId::generate()->value,
                        "actor_id" => $this->actor()->value,
                        "expires_at" => $review["expires_at"],
                        "reason" => $this->reason($reason),
                        "rows" => array_map(
                            static fn(array $row): array => array_diff_key(
                                $row,
                                ["issues" => true],
                            ),
                            $review["rows"],
                        ),
                    ],
                    JSON_THROW_ON_ERROR |
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_LINE_TERMINATORS,
                ),
            );
        }
        return $review;
    }

    /** Public large-file review; only the server worker creates small processing proofs. */
    public function analyze(
        #[\SensitiveParameter] string $csv,
        array $mapping = [],
        string $delimiter = ",",
        #[\SensitiveParameter] string $reason = "",
    ): array {
        $actor = $this->actor();
        $prepared = $this->mapper->prepare($csv, $mapping, $delimiter);
        $rows = $values = $emails = [];
        foreach ($prepared["rows"] as $input) {
            try {
                $value = PersonImportRow::from($input);
                $values[] = $value;
                $emails[$value->email] = ($emails[$value->email] ?? 0) + 1;
                $rows[] = $value->values() + ["issues" => []];
            } catch (InvalidArgumentException $error) {
                $rows[] = [
                    "row" => $input["row"],
                    "given_name" => mb_substr($input["given_name"], 0, 191),
                    "family_name" => mb_substr($input["family_name"], 0, 191),
                    "email" => mb_substr($input["email"], 0, 191),
                    "issues" => [$error->getMessage()],
                ];
            }
        }
        $existing = array_fill_keys(
            $this->imports->existingEmails(array_keys($emails)),
            true,
        );
        foreach ($rows as &$row) {
            if ($row["issues"] !== []) {
                continue;
            }
            if (($emails[$row["email"]] ?? 0) > 1) {
                $row["issues"][] = trans_html(
                    "This email appears more than once in the file.",
                );
            }
            if (isset($existing[$row["email"]])) {
                $row["issues"][] = trans_html(
                    "An account already uses this email. Use its existing person record.",
                );
            }
        }
        unset($row);
        $ready =
        $mapping !== [] &&
        $rows !== [] &&
        count($values) === count($rows) &&
        array_filter(
            $rows,
            static fn(array $row): bool => $row["issues"] !== [],
        ) === [];
        $token = $expires = null;
        if ($ready) {
            $reason = $this->reason($reason);
        }
        $this->actor($actor);
        return [
            "headers" => $prepared["headers"],
            "rows" => $rows,
            "ready" => $ready,
            "review_token" => $token,
            "expires_at" => $expires,
        ];
    }

    /** @return array<string,mixed> */
    public function commit(#[\SensitiveParameter] string $token): array
    {
        $actor = $this->actor();
        try {
            if ($token === "" || strlen($token) > 65536) {
                throw new InvalidArgumentException();
            }
            $ticket = json_decode(
                $this->cipher->open($token),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($ticket) ||
                count($ticket) !== 6 ||
                ($ticket["kind"] ?? null) !== "person-import-review" ||
                !is_string($ticket["operation_id"] ?? null) ||
                !is_string($ticket["actor_id"] ?? null) ||
                !is_int($ticket["expires_at"] ?? null) ||
                !is_string($ticket["reason"] ?? null) ||
                !is_array($ticket["rows"] ?? null) ||
                !array_is_list($ticket["rows"]) ||
                count($ticket["rows"]) < 1 ||
                count($ticket["rows"]) > 10
            ) {
                throw new InvalidArgumentException();
            }
            $operation = new EntityId($ticket["operation_id"]);
            $reason = $this->reason($ticket["reason"]);
            $rows = array_map(PersonImportRow::from(...), $ticket["rows"]);
            if (
                count(array_unique(array_column($ticket["rows"], "row"))) !==
                    count($rows) ||
                count(
                    array_unique(
                        array_map(
                            static fn(
                                PersonImportRow $row,
                            ): string => $row->email,
                            $rows,
                        ),
                    ),
                ) !== count($rows)
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException(
                trans_html("Review the people import again."),
            );
        }
        if ($ticket["actor_id"] !== $actor->value) {
            throw new AccessDeniedException(
                trans_html("This people-import review belongs to another account."),
            );
        }
        $hash = hash("sha256", $token);
        try {
            return $this->transactions->run(function () use (
                $actor,
                $operation,
                $hash,
                $rows,
                $ticket,
                $reason,
            ): array {
                $receipt = $this->imports->receipt($operation);
                if ($receipt !== null) {
                    return $this->completed($receipt, $actor, $hash);
                }
                $this->fresh($ticket["expires_at"]);
                $this->actor($actor);
                $result = [
                    "operation_id" => $operation->value,
                    "created" => count($rows),
                    "people" => [],
                ];
                $sources = [];
                foreach ($rows as $row) {
                    $rowOperation = DerivedIdentity::from(
                        $operation,
                        "person-import.row." . $row->row,
                    );
                    $person = $this->people->staff(
                        $rowOperation,
                        $row->givenName,
                        $row->familyName,
                        $row->email,
                    );
                    $source = $this->imports->source($person);
                    if (
                        $source["given_name"] !== $row->givenName ||
                        $source["family_name"] !== $row->familyName ||
                        $source["email"] !== $row->email ||
                        $source["owner"] !== $person->ownerUserId?->value ||
                        $source["institutional_id"] !==
                            $person->institutionalId->value ||
                        $source["setup_id"] !== $rowOperation->value ||
                        $source["setup_user"] !== $source["owner"] ||
                        $source["setup_state"] !== "queued" ||
                        $source["setup_consumed_at"] !== null
                    ) {
                        throw new ConcurrentModificationException(
                            trans_html(
                                "A new person or account changed. Review the import again.",
                            ),
                        );
                    }
                    $sources[] = [$person, $source];
                    $result["people"][] = [
                        "row" => $row->row,
                        "operation_id" => $rowOperation->value,
                        "person_id" => $person->id->value,
                        "account_id" => $person->ownerUserId->value,
                        "institutional_id" => $person->institutionalId->value,
                    ];
                }
                $encrypted = $this->cipher->seal(
                    json_encode(
                        ["reason" => $reason, "result" => $result],
                        JSON_THROW_ON_ERROR,
                    ),
                );
                $this->imports->record(
                    $operation,
                    $actor,
                    $hash,
                    $encrypted,
                    $this->clock
                        ->now()
                        ->setTimezone(new DateTimeZone("UTC"))
                        ->format("Y-m-d\TH:i:s.u"),
                );
                $this->audit->record(
                    $actor,
                    "person_import.completed",
                    $operation,
                );
                $this->actor($actor);
                $this->fresh($ticket["expires_at"]);
                foreach ($sources as [$person, $source]) {
                    if ($this->imports->source($person) !== $source) {
                        throw new ConcurrentModificationException(
                            trans_html(
                                "A new person or account changed. Review the import again.",
                            ),
                        );
                    }
                }
                $saved = $this->imports->receipt($operation);
                if (
                    $saved === null ||
                    $saved["encrypted_receipt"] !== $encrypted ||
                    $this->completed($saved, $actor, $hash) !== $result
                ) {
                    throw new ConcurrentModificationException(
                        trans_html(
                            "The people-import receipt changed before saving completed.",
                        ),
                    );
                }
                return $result;
            });
        } catch (PDOException | ConcurrentModificationException $error) {
            // A competing identical confirmation may have committed while this transaction rolled back.
            $this->actor($actor);
            $receipt = $this->imports->receipt($operation);
            if ($receipt !== null) {
                return $this->completed($receipt, $actor, $hash);
            }
            if ($error instanceof PDOException) {
                throw new ConcurrentModificationException(
                    trans_html(
                        "People changed during confirmation. Review the import again.",
                    ),
                );
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $receipt
     * @return array<string,mixed> */
    private function completed(
        array $receipt,
        EntityId $actor,
        string $hash,
    ): array {
        if (
            $receipt["actor_id"] !== $actor->value ||
            !hash_equals($receipt["proof_hash"], $hash)
        ) {
            throw new AccessDeniedException(
                trans_html("This people-import receipt belongs to another request."),
            );
        }
        try {
            $stored = json_decode(
                $this->cipher->open($receipt["encrypted_receipt"]),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
            $result = $stored["result"];
            if (
                $result["operation_id"] !== $receipt["id"] ||
                !is_int($result["created"]) ||
                $result["created"] < 1 ||
                $result["created"] > 10 ||
                !array_is_list($result["people"]) ||
                count($result["people"]) !== $result["created"]
            ) {
                throw new CorruptHistoryException();
            }
            foreach ($result["people"] as $row) {
                new EntityId($row["person_id"]);
                new EntityId($row["account_id"]);
                new EntityId($row["operation_id"]);
                new \Domain\Person\ValueObject\InstitutionalId(
                    $row["institutional_id"],
                );
            }
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
        $this->actor($actor);
        return $result;
    }

    private function actor(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:import:manage");
        if (
            $this->access->requirePermission("sis:person:manage")->value !==
                $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException(
                trans_html("The people-import authority changed."),
            );
        }
        return $actor;
    }

    private function fresh(int $expires): void
    {
        if ($this->clock->now()->getTimestamp() >= $expires) {
            throw new ConcurrentModificationException(
                trans_html(
                    "The people-import review expired. Review the file again.",
                ),
            );
        }
    }

    private function reason(#[\SensitiveParameter] string $value): string
    {
        $value = trim($value);
        if (
            $value === "" ||
            mb_strlen($value) > 500 ||
            !mb_check_encoding($value, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException(
                trans_html("Provide an import reason up to 500 characters."),
            );
        }
        return $value;
    }
}
