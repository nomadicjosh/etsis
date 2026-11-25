<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Support\HistoryCheckReceipt;
use Domain\Support\HistoryCheckScope;
use Domain\Support\Enum\HistoryCheckSubject;
use Domain\Support\Repository\HistoryCheckRepository;
use Infrastructure\Persistence\SqlRecords;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlHistoryCheckRepository implements HistoryCheckRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function accountStudent(EntityId $account): EntityId
    {
        $q = $this->sql->quote(...);
        $row =
        $this->sql->fetch(
            "SELECT " .
                    $q("student_id") .
                    " FROM " .
                    $q("sis_student_accounts") .
                    " WHERE " .
                    $q("id") .
                    " = ?",
            [$account->value],
            1,
        )[0] ?? throw new RecordNotFoundException("Account not found.");
        return new EntityId($row["student_id"]);
    }

    public function creditStudent(EntityId $credit): EntityId
    {
        $selected =
        new SqlAcademicInputRepository($this->sql)->selected($credit) ??
        throw new RecordNotFoundException(
            trans("Academic source routing not found."),
        );
        return new EntityId($selected["student_id"]);
    }

    public function save(HistoryCheckReceipt $receipt): void
    {
        $this->sql->insert(
            "sis_history_checks",
            $receipt->scope->criteria() + [
                "id" => $receipt->id->value,
                "actor_id" => $receipt->actor->value,
                "status" => $receipt->status,
                "events_checked" => $receipt->events,
                "checked_at" => $receipt->checkedAt,
            ],
        );
    }

    public function employmentSubject(HistoryCheckSubject $kind, EntityId $id): EntityId
    {
        if ($kind === HistoryCheckSubject::PrimaryPosition) {
            return $id;
        }
        $table = match ($kind) {
            HistoryCheckSubject::Position => 'sis_employee_positions',
            HistoryCheckSubject::TimeEntry => 'sis_timesheets',
            default => throw new \InvalidArgumentException('Choose an employment history subject.'),
        };
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch('SELECT ' . $q('employee_id') . ' FROM ' . $q($table)
            . ' WHERE ' . $q('id') . ' = ?', [$id->value], 1)[0]
        ?? throw new RecordNotFoundException('Employment history subject not found.');
        return new EntityId($row['employee_id']);
    }

    public function page(HistoryCheckScope $scope, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = $parameters = [];
        foreach ($scope->criteria() as $key => $value) {
            $where[] = $q($key) . ($value === null ? " IS NULL" : " = ?");
            if ($value !== null) {
                $parameters[] = $value;
            }
        }
        if ($after !== null) {
            $where[] = $q("id") . " < ?";
            $parameters[] = $after->value;
        }
        return array_map(
            fn(array $row): HistoryCheckReceipt => $this->open($row, $scope),
            $this->sql->fetch(
                "SELECT * FROM " .
                    $q("sis_history_checks") .
                    " WHERE " .
                    implode(" AND ", $where) .
                    " ORDER BY " .
                    $q("id") .
                    " DESC",
                $parameters,
                11,
            ),
        );
    }
    private function open(
        #[\SensitiveParameter] array $row,
        HistoryCheckScope $scope,
    ): HistoryCheckReceipt {
        try {
            foreach ($scope->criteria() as $key => $value) {
                if (!array_key_exists($key, $row) || $row[$key] !== $value) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
            }
            if ((string) (int) $row["events_checked"] !== (string) $row["events_checked"]) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            return new HistoryCheckReceipt(
                new EntityId($row["id"]),
                $scope,
                new EntityId($row["actor_id"]),
                $row["status"],
                (int) $row["events_checked"],
                $row["checked_at"],
            );
        } catch (\Throwable) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
    }
}
