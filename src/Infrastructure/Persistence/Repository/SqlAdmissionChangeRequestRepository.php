<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\AdmissionChangeRequest;
use Domain\Admissions\Repository\AdmissionChangeRequestRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlAdmissionChangeRequestRepository implements AdmissionChangeRequestRepository
{
    private const string TABLE = "sis_admission_change_requests";

    public function __construct(
        private SqlRecords $sql,
        private MessageCipher $cipher,
    ) {
    }

    public function find(
        EntityId $application,
        EntityId $id,
    ): ?AdmissionChangeRequest {
        $rows = $this->sql->rows(
            self::TABLE,
            ["application_id" => $application->value, "id" => $id->value],
            1,
        );
        $row = $rows[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function count(EntityId $application): int
    {
        $q = $this->sql->quote(...);
        return (int) $this->sql->fetch(
            "SELECT COUNT(*) AS " .
                $q("n") .
                " FROM " .
                $q(self::TABLE) .
                " WHERE " .
                $q("application_id") .
                " = ?",
            [$application->value],
            1,
        )[0]["n"];
    }

    public function pending(EntityId $application, string $area): bool
    {
        $rows = $this->sql->rows(
            self::TABLE,
            [
                "application_id" => $application->value,
                "area" => $area,
                "status" => "requested",
            ],
            2,
        );
        if (count($rows) > 1) {
            throw new CorruptHistoryException();
        }
        foreach ($rows as $row) {
            $this->open($row);
        }
        return $rows !== [];
    }

    public function page(
        EntityId $application,
        ?EntityId $owner,
        bool $finished,
        ?EntityId $after,
    ): array {
        $q = $this->sql->quote(...);
        $where = $q("application_id") .
        " = ? AND " .
        $q("status") .
        ($finished ? " IN (?, ?)" : " = ?");
        $args = [
            $application->value,
            ...$finished ? ["closed", "cancelled"] : ["requested"],
        ];
        if ($owner !== null) {
            $where .= " AND " . $q("owner_user_id") . " = ?";
            $args[] = $owner->value;
        }
        if ($after !== null) {
            $where .= " AND " . $q("id") . " < ?";
            $args[] = $after->value;
        }
        return array_map(
            $this->open(...),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q(self::TABLE) .
                    " WHERE " .
                    $where .
                    " ORDER BY " .
                    $q("id") .
                    " DESC",
                $args,
                11,
            ),
        );
    }

    private function open(
        #[\SensitiveParameter] array $row,
    ): AdmissionChangeRequest {
        try {
            if (
                !is_string($row["sealed_data"]) ||
                strlen($row["sealed_data"]) > 32768
            ) {
                throw new CorruptHistoryException();
            }
            $record = AdmissionChangeRequest::restore(
                json_decode(
                    $this->cipher->open($row["sealed_data"]),
                    true,
                    12,
                    JSON_THROW_ON_ERROR,
                ),
            );
            $facts = $record->facts();
            $fields = [
                    "id",
                    "application_id",
                    "person_id",
                    "owner_user_id",
                    "area",
                    "status",
                    "version",
            ];
            foreach ($fields as $key) {
                if ((string) $row[$key] !== (string) $facts[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            return $record;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function save(
        AdmissionChangeRequest $request,
        ?int $expectedVersion,
    ): void {
        $facts = $request->facts();
        $row = array_intersect_key(
            $facts,
            array_flip([
                "id",
                "application_id",
                "person_id",
                "owner_user_id",
                "area",
                "status",
                "version",
            ]),
        );
        $row["sealed_data"] = $this->cipher->seal(
            json_encode($facts, JSON_THROW_ON_ERROR),
        );
        if (strlen($row["sealed_data"]) > 32768) {
            throw new CorruptHistoryException();
        }
        if ($expectedVersion === null) {
            try {
                $this->sql->insert(self::TABLE, $row);
            } catch (PDOException $error) {
                if (
                    in_array(
                        (string) $error->getCode(),
                        ["23000", "23505"],
                        true,
                    )
                ) {
                    throw new ConcurrentModificationException(
                        trans("Refresh this follow-up before responding."),
                    );
                }
                throw $error;
            }
        } elseif (
            $this->sql->update(self::TABLE, $row, [
                "id" => $facts["id"],
                "application_id" => $facts["application_id"],
                "version" => $expectedVersion,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                trans("Refresh this follow-up before responding."),
            );
        }
    }
}
