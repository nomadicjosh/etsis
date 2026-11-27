<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\ServiceFee\ServiceFee;
use Domain\ServiceFee\ValueObject\FeePolicy;
use Domain\ServiceFee\ValueObject\FeeSchema;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlServiceFeeRepository implements ServiceFeeRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function policy(string $purpose): FeePolicy
    {
        FeeSchema::purpose($purpose);
        $r =
        $this->sql->rows(
            "sis_service_fee_policies",
            ["purpose" => $purpose],
            1,
        )[0] ?? null;
        return $r === null
        ? new FeePolicy(0, 0, "USD", true)
        : new FeePolicy(
            (int) $r["version"],
            (int) $r["amount_minor"],
            $r["currency"],
            match ($r['allow_waivers']) {
                true, 1, '1', 't' => true,
                false, 0, '0', 'f' => false,
                default => throw new \LogicException('Invalid stored waiver policy.'),
            },
        );
    }
    public function savePolicy(
        string $purpose,
        FeePolicy $policy,
        int $expected,
    ): void {
        if ($expected === 0) {
            try {
                $this->sql->insert("sis_service_fee_policies", [
                    "id" => \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
                        new EntityId("00000000000000000000000001"),
                        "fee.policy." . $purpose,
                    )->value,
                    "purpose" => $purpose,
                    "version" => $policy->revision,
                    "amount_minor" => $policy->minor,
                    "currency" => $policy->currency,
                    "allow_waivers" => (int) $policy->waivers,
                ]);
            } catch (\PDOException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw new ConcurrentModificationException('The fee policy changed. Reload it.');
                }
                throw $error;
            }
        } elseif (
            $this->sql->update(
                "sis_service_fee_policies",
                [
                    "version" => $policy->revision,
                    "amount_minor" => $policy->minor,
                    "currency" => $policy->currency,
                    "allow_waivers" => (int) $policy->waivers,
                ],
                ["purpose" => $purpose, "version" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The fee policy changed. Reload it.",
            );
        }
    }
    public function find(EntityId $id): ?array
    {
        return $this->sql->rows(
            "sis_service_fees",
            ["id" => $id->value],
            1,
        )[0] ?? null;
    }
    public function active(
        EntityId $person,
        EntityId $owner,
        string $purpose,
        array $target,
    ): ?array {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_service_fees") .
                " WHERE " .
                $q("person_id") .
                " = ? AND " .
                $q("owner_user_id") .
                " = ? AND " .
                $q("purpose") .
                " = ? AND " .
                $q("target_hash") .
                " = ? AND " .
                $q("applied_to") .
                " IS NULL ORDER BY " .
                $q("id") .
                " DESC",
            [$person->value, $owner->value, $purpose, FeeSchema::hash($target)],
            1,
        )[0] ?? null;
    }
    public function save(ServiceFee $root, int $expected): void
    {
        $s = $root->state();
        $a = $s["assessment"];
        $r = [
            "id" => (string) $root->aggregateId(),
            "person_id" => $a["person_id"],
            "owner_user_id" => $a["owner_id"],
            "purpose" => $a["purpose"],
            "target_hash" => FeeSchema::hash($a["target"]),
            "version" => $root->playhead(),
            "source_hash" => FeeSchema::hash($s),
            "applied_to" => $s["applied_to"],
        ];
        if ($expected === 0) {
            $this->sql->insert("sis_service_fees", $r);
        } elseif (
            $this->sql->update("sis_service_fees", $r, [
                "id" => $r["id"],
                "version" => $expected,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "This fee changed. Review it again.",
            );
        }
    }
    public function page(
        EntityId $person,
        ?EntityId $owner,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_service_fees") .
                " WHERE " .
                $q("person_id") .
                " = ?" .
                ($owner === null
                    ? ""
                    : " AND " . $q("owner_user_id") . " = ?") .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            [
                $person->value,
                ...$owner === null ? [] : [$owner->value],
                ...$after === null ? [] : [$after->value],
            ],
            11,
        );
    }
}
