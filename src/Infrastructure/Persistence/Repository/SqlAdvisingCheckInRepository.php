<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Advising\Repository\AdvisingCheckInRepository;
use Domain\Advising\AdvisingCheckIn;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdvisingCheckInRepository implements AdvisingCheckInRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    public function find(EntityId $student, EntityId $pursuit, EntityId $id): ?AdvisingCheckIn
    {
        $row =
        $this->sql->rows(
            "sis_advising_checkins",
            [
                    "student_id" => $student->value,
                    "pursuit_id" => $pursuit->value,
                    "id" => $id->value,
                ],
            1,
        )[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function activeCount(EntityId $student, EntityId $pursuit): int
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_advising_checkins") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("pursuit_id") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?)",
            [$student->value, $pursuit->value, "open", "responded"],
            21,
        );
        foreach ($rows as $row) {
            $this->open($row);
        }
        return count($rows);
    }

    public function page(
        EntityId $student,
        EntityId $pursuit,
        ?EntityId $owner,
        bool $finished,
        ?EntityId $after,
    ): array {
        $q = $this->sql->quote(...);
        $where =
        $q("student_id") .
        " = ? AND " .
        $q("pursuit_id") .
        " = ? AND " .
        $q("status") .
        " IN (?, ?)";
        $parameters = [
            $student->value,
            $pursuit->value,
            ...$finished ? ["closed", "cancelled"] : ["open", "responded"],
        ];
        if ($owner !== null) {
            $where .= " AND " . $q("owner_user_id") . " = ?";
            $parameters[] = $owner->value;
        }
        if ($after !== null) {
            $where .= " AND " . $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        return array_map(
            $this->open(...),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_advising_checkins") .
                    " WHERE " .
                    $where .
                    " ORDER BY " .
                    $q("id") .
                    " DESC",
                $parameters,
                11,
            ),
        );
    }

    private function open(#[\SensitiveParameter] array $row): AdvisingCheckIn
    {
        $request = \Infrastructure\Persistence\AdvisingCheckInStorage::open($row, $this->cipher);
        foreach (\Infrastructure\Persistence\AdvisingCheckInStorage::dates($request) as $key => $value) {
            if (!array_key_exists($key, $row) || $row[$key] !== $value) {
                throw new CorruptHistoryException();
            }
        }
        return $request;
    }

    public function save(AdvisingCheckIn $request, ?int $expectedVersion): void
    {
        $data = $request->facts();
        $row = array_intersect_key(
            $data,
            array_flip(["id", "student_id", "pursuit_id", "owner_user_id", "status", "version"]),
        );
        $row += \Infrastructure\Persistence\AdvisingCheckInStorage::dates($request);
        $row["sealed_data"] = $this->cipher->seal(
            json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
        if (strlen($row["sealed_data"]) > 65536) {
            throw new \Domain\Shared\Exception\ValidationException([
                ["field" => "message", "message" => "Use shorter advising check-in messages."],
            ]);
        }
        if ($expectedVersion === null) {
            try {
                $this->sql->insert("sis_advising_checkins", $row);
            } catch (\PDOException $exception) {
                if (in_array((string) $exception->getCode(), ["23000", "23505"], true)) {
                    throw new ConcurrentModificationException(
                        "This check-in reference was already used. Refresh check-ins.",
                    );
                }
                throw $exception;
            }
        } elseif (
            $this->sql->update("sis_advising_checkins", $row, [
                "id" => $data["id"],
                "student_id" => $data["student_id"],
                "pursuit_id" => $data["pursuit_id"],
                "version" => $expectedVersion,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The advising check-in changed. Refresh its status.",
            );
        }
    }
}
