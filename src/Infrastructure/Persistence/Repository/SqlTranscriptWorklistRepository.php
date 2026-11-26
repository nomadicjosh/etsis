<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptWorklistRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTranscriptWorklistRepository implements TranscriptWorklistRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function request(EntityId $request): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'student_id', 'status', 'version']))
            . ' FROM ' . $q('sis_transcript_requests') . ' WHERE ' . $q('id') . ' = ?', [$request->value], 1)[0]
        ?? throw new \Domain\Shared\Exception\RecordNotFoundException();
    }
    public function assignment(EntityId $request): ?array
    {
        return $this->sql->rows('sis_transcript_assignments', ['id' => $request->value], 1)[0] ?? null;
    }
    public function save(EntityId $request, int $version, ?EntityId $assignee, ?EntityId $person, string $at): void
    {
        $values = ['version' => $version + 1, 'assignee_id' => $assignee?->value,
            'assignee_person_id' => $person?->value, 'updated_at' => $at];
        if ($version === 0) {
            $this->sql->insert('sis_transcript_assignments', ['id' => $request->value] + $values);
        } else {
            $this->sql->update('sis_transcript_assignments', $values, ['id' => $request->value, 'version' => $version]);
        }
    }
    public function page(
        EntityId $actor,
        EntityId $person,
        string $scope,
        string $status,
        string $search,
        ?EntityId $after = null
    ): array {
        $q = $this->sql->quote(...);
        $where = [];
        $args = [];
        if ($scope === 'mine') {
            $where[] = 'a.' . $q('assignee_id') . ' = ? AND a.' . $q('assignee_person_id') . ' = ?';
            array_push($args, $actor->value, $person->value);
        } elseif ($scope === 'unassigned') {
            $where[] = 'a.' . $q('assignee_id') . ' IS NULL';
        }
        if ($status === 'active' || $status === 'finished') {
            $where[] = 'r.' . $q('status') . ($status === 'active' ? ' IN (?, ?)' : ' NOT IN (?, ?)');
            array_push($args, 'pending', 'ready');
        } elseif ($status !== 'all') {
            $where[] = 'r.' . $q('status') . ' = ?';
            $args[] = $status;
        }
        if ($search !== '') {
            $fields = ['id', 'delivery', 'status'];
            $where[] = '(' . implode(' OR ', array_map(static fn (string $f): string =>
                'LOWER(r.' . $q($f) . ") LIKE ? ESCAPE '!'", $fields)) . ')';
            $like = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($args, $like, $like, $like);
        }
        if ($after !== null) {
            $where[] = 'r.' . $q('id') . ' < ?';
            $args[] = $after->value;
        }
        $fields = array_map(
            static fn (string $f): string => 'r.' . $q($f),
            ['id', 'student_id', 'delivery', 'status', 'requested_at', 'version']
        );
        return $this->sql->fetch('SELECT ' . implode(', ', $fields)
            . ', a.' . $q('assignee_id') . ', a.' . $q('assignee_person_id')
            . ', p.' . $q('id') . ' AS ' . $q('linked_person_id')
            . ', p.' . $q('given_name') . ', p.' . $q('family_name')
            . ' FROM ' . $q('sis_transcript_requests') . ' r LEFT JOIN ' . $q('sis_transcript_assignments')
            . ' a ON a.' . $q('id') . ' = r.' . $q('id')
            . ' LEFT JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id') . ' = a.' . $q('assignee_person_id')
            . ' AND p.' . $q('owner_user_id') . ' = a.' . $q('assignee_id')
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY r.' . $q('id') . ' DESC', $args, 21);
    }
}
