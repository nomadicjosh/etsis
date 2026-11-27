<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\ServiceFee\ValueObject\FeeSchema;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Internal gate. Submission/request writers authorize, hold the person lock and share their transaction. */
final readonly class FeeClearance
{
    public function __construct(
        private ServiceFeeRepository $records,
        private ServiceFeeLedger $ledger,
        private AuditTrail $audit,
        private Clock $clock,
        private \Domain\Admissions\Repository\AdmissionsRepository $admissions,
    ) {
    }
    public function apply(
        string $purpose,
        EntityId $person,
        ?EntityId $owner,
        array $target,
        EntityId $acceptedId,
        EntityId $actor,
    ): ?array {
        FeeSchema::purpose($purpose);
        FeeSchema::target($purpose, $target);
        if ($owner === null) {
            if ($this->records->policy($purpose)->minor === 0) {
                return null;
            }
            self::required();
        }
        $row = $this->records->active($person, $owner, $purpose, $target);
        if ($row === null) {
            if ($this->records->policy($purpose)->minor === 0) {
                return null;
            }
            self::required();
        }
        $id = new EntityId($row["id"]);
        $root = $this->ledger->load($id);
        $state = $root->state();
        $a = $state["assessment"];
        if (
            $a["person_id"] !== $person->value ||
            $a["owner_id"] !== $owner->value ||
            $a["purpose"] !== $purpose ||
            FeeSchema::hash($a["target"]) !== FeeSchema::hash($target) ||
            !$root->cleared()
        ) {
            self::required();
        }
        $operation = PublishedGradeId::forVersion($id, "service.fee.apply");
        $details = ["target_id" => $acceptedId->value];
        $reason =
        "Cleared service fee applied to its independently authorized application or official transcript request.";
        $this->audit->record($actor, "service_fee.applied", $id);
        $root = $this->ledger->record(
            $id,
            $operation,
            "apply",
            $details,
            $root->playhead(),
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                new EntityId($state["decision_id"]),
                $reason,
            ),
        );
        return $root->state()["applied_reference"];
    }
    public static function validateReference(array $ref): void
    {
        FeeSchema::keys($ref, [
            "fee_id",
            "decision_id",
            "minor",
            "currency",
            "waived",
            "version",
        ]);
        new EntityId($ref["fee_id"]);
        new EntityId($ref["decision_id"]);
        FeeSchema::amount($ref["minor"]);
        new \Domain\Finance\ValueObject\Money($ref["minor"], $ref["currency"]);
        if (
            !is_bool($ref["waived"]) ||
            !is_int($ref["version"]) ||
            $ref["version"] < 1
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
    }
    public function assertApplied(
        ?array $ref,
        string $purpose,
        EntityId $person,
        ?EntityId $owner,
        array $target,
        EntityId $accepted,
    ): void {
        if ($ref === null) {
            if (
                $this->records->policy($purpose)->minor > 0 ||
                ($owner !== null &&
                    $this->records->active(
                        $person,
                        $owner,
                        $purpose,
                        $target,
                    ) !== null)
            ) {
                self::required();
            }
            return;
        }
        $root = $this->ledger->load(new EntityId($ref["fee_id"]));
        $s = $root->state();
        $a = $s["assessment"];
        if (
            $root->playhead() !== $ref["version"] ||
            $s["applied_to"] !== $accepted->value ||
            FeeSchema::hash($s["applied_reference"]) !==
                FeeSchema::hash($ref) ||
            $a["person_id"] !== $person->value ||
            $a["owner_id"] !== $owner?->value ||
            $a["purpose"] !== $purpose ||
            FeeSchema::hash($a["target"]) !== FeeSchema::hash($target)
        ) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                "The fee clearance changed during submission.",
            );
        }
    }

    public function verifyTranscript(array $request): void
    {
        if (($request["fee_id"] ?? null) === null) {
            return;
        }
        $person = $this->admissions->student(
            new EntityId($request["student_id"]),
        )->personId;
        $root = $this->ledger->load(new EntityId($request["fee_id"]));
        $s = $root->state();
        $a = $s["assessment"];
        if (
            $s["applied_reference"] === null ||
            $s["applied_to"] !== $request["id"] ||
            $a["purpose"] !== "transcript" ||
            $a["person_id"] !== $person->value ||
            $a["owner_id"] !== $request["owner_user_id"] ||
            $a["target"]["student_id"] !== $request["student_id"]
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
    }
    private static function required(): never
    {
        throw new ValidationException([
            [
                "field" => "fee",
                "message" =>
                    trans(
                        "Complete the required service fee payment or obtain an authorized waiver " .
                        "before submitting."
                    ),
            ],
        ]);
    }
}
