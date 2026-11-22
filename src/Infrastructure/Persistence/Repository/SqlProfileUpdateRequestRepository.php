<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\Repository\ProfileUpdateRequestRepository;
use Domain\Person\ProfileUpdateRequest;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlProfileUpdateRequestRepository implements ProfileUpdateRequestRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    public function find(EntityId $person, EntityId $id): ?ProfileUpdateRequest
    {
        $row =
        $this->sql->rows(
            "sis_profile_update_requests",
            ["person_id" => $person->value, "id" => $id->value],
            1,
        )[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function pending(EntityId $person, string $area): bool
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_profile_update_requests") .
                " WHERE " .
                $q("person_id") .
                " = ? AND " .
                $q("area") .
                " = ? AND " .
                $q("status") .
                " IN (?, ?)",
            [$person->value, $area, "requested", "responded"],
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
        EntityId $person,
        ?EntityId $owner,
        bool $finished,
        ?EntityId $after,
        ?string $area = null,
    ): array {
        $q = $this->sql->quote(...);
        $where = $q("person_id") . " = ? AND " . $q("status") . " IN (?, ?)";
        $parameters = [
            $person->value,
            ...$finished ? ["closed", "cancelled"] : ["requested", "responded"],
        ];
        if ($owner !== null) {
            $where .= " AND " . $q("owner_user_id") . " = ?";
            $parameters[] = $owner->value;
        }
        if ($area !== null) {
            $where .= ' AND ' . $q('area') . ' = ?';
            $parameters[] = $area;
        }
        if ($after !== null) {
            $where .= " AND " . $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        return array_map(
            $this->open(...),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_profile_update_requests") .
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

    private function open(#[\SensitiveParameter] array $row): ProfileUpdateRequest
    {
        try {
            if (!is_string($row["sealed_data"]) || strlen($row["sealed_data"]) > 32768) {
                throw new CorruptHistoryException();
            }
            $data = json_decode(
                $this->cipher->open($row["sealed_data"]),
                true,
                12,
                JSON_THROW_ON_ERROR,
            );
            $request = ProfileUpdateRequest::restore($data);
            foreach (["id", "person_id", "owner_user_id", "area", "status", "version"] as $key) {
                if ((string) $row[$key] !== (string) $data[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            return $request;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function save(ProfileUpdateRequest $request, ?int $expectedVersion): void
    {
        $data = $request->facts();
        $row = array_intersect_key(
            $data,
            array_flip(["id", "person_id", "owner_user_id", "area", "status", "version"]),
        );
        $row["sealed_data"] = $this->cipher->seal(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        if (strlen($row["sealed_data"]) > 32768) {
            throw new \Domain\Shared\Exception\ValidationException([
                ['field' => 'message', 'message' => 'Use shorter profile-request messages.'],
            ]);
        }
        if ($expectedVersion === null) {
            try {
                $this->sql->insert("sis_profile_update_requests", $row);
            } catch (\PDOException $exception) {
                if (in_array((string) $exception->getCode(), ["23000", "23505"], true)) {
                    throw new ConcurrentModificationException(
                        "This request reference was already used. Refresh requests.",
                    );
                }
                throw $exception;
            }
        } elseif (
            $this->sql->update("sis_profile_update_requests", $row, [
                "id" => $data["id"],
                "person_id" => $data["person_id"],
                "version" => $expectedVersion,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The profile request changed. Refresh its status.",
            );
        }
    }
}
