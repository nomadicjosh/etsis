<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Import\Repository\PersonImportRepository;
use Domain\Person\Person;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPersonImportRepository implements PersonImportRepository
{
    public function __construct(
        private SqlRecords $sql,
        private AdmissionsRepository $people,
    ) {
    }

    public function receipt(EntityId $operation): ?array
    {
        return $this->sql->current("sis_person_imports", $operation->value);
    }

    public function existingEmails(#[\SensitiveParameter] array $emails): array
    {
        $q = $this->sql->quote(...);
        $found = [];
        $iterationValues = array_chunk(array_values(array_unique($emails)), 200);
        foreach ($iterationValues as $chunk) {
            $rows = $this->sql->fetch(
                "SELECT DISTINCT LOWER(" .
                    $q("email") .
                    ") AS " .
                    $q("email") .
                    " FROM " .
                    $q("users") .
                    " WHERE LOWER(" .
                    $q("email") .
                    ") IN (" .
                    implode(",", array_fill(0, count($chunk), "?")) .
                    ")",
                $chunk,
                201,
            );
            foreach ($rows as $row) {
                $found[] = $row["email"];
            }
        }
        return $found;
    }

    public function record(
        EntityId $operation,
        EntityId $actor,
        string $proofHash,
        string $encrypted,
        string $at,
    ): void {
        $this->sql->insert("sis_person_imports", [
            "id" => $operation->value,
            "actor_id" => $actor->value,
            "proof_hash" => $proofHash,
            "encrypted_receipt" => $encrypted,
            "created_at" => $at,
        ]);
    }

    public function source(#[\SensitiveParameter] Person $person): array
    {
        $current = $this->people->person($person->id);
        $user =
        $current->ownerUserId === null
        ? null
        : $this->sql->current(
            "users",
            $current->ownerUserId->value,
            "user_id",
        );
        $setup =
        $this->sql->rows(
            "sis_account_setup",
            ["person_id" => $person->id->value],
            1,
        )[0] ?? null;
        return [
            "id" => $current->id->value,
            "owner" => $current->ownerUserId?->value,
            "given_name" => $current->givenName,
            "family_name" => $current->familyName,
            "institutional_id" => $current->institutionalId->value,
            "version" => $current->identityVersion,
            "email" => $user["email"] ?? null,
            "role" => $user["role"] ?? null,
            "setup_id" => $setup["id"] ?? null,
            "setup_user" => $setup["user_id"] ?? null,
            "setup_state" => $setup["state"] ?? null,
            "setup_consumed_at" => $setup["consumed_at"] ?? null,
        ];
    }
}
