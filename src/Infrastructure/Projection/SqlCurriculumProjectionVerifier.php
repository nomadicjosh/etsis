<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Catalog\ProgramPublication;
use Domain\Catalog\ValueObject\CatalogProjectionCheck;
use Domain\Catalog\ValueObject\ProgramPublicationId;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\DegreeAudit\CourseGroupPublication;
use Domain\DegreeAudit\RequirementsPublication;
use Domain\DegreeAudit\ValueObject\CourseGroupPublicationId;
use Domain\DegreeAudit\ValueObject\RequirementsPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Selected publication streams and their own projections; no dependent student evaluation or repair. */
final readonly class SqlCurriculumProjectionVerifier
{
    public function __construct(private SqlRecords $sql, private HistoricalEventStore $history)
    {
    }

    public function context(string $kind, EntityId $id): array
    {
        $row = $this->identity($kind, $id);
        if ($row === null) {
            throw new RecordNotFoundException('Catalog publication record not found.');
        }
        return array_intersect_key($row, array_flip(['id', 'program_id', 'academic_year_id', 'specialization_id']));
    }

    public function verify(string $kind, EntityId $id, int $limit = 500): CatalogProjectionCheck
    {
        $this->type($kind);
        if ($limit < 1 || $limit > 2000) {
            throw new InvalidArgumentException('Use a catalog verification allowance of 1–2000 events.');
        }
        if ($this->sql->inTransaction()) {
            return new CatalogProjectionCheck($kind, $id, 'unverified', 0, issues: ['unavailable']);
        }
        $budget = new HistoryReadBudget(maximumEvents: $limit, maximumBytes: 4_194_304, maximumMilliseconds: 5000);
        $before = null;
        $status = 'unverified';
        $issue = 'catalog_identity';
        $count = null;
        try {
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($kind, $id);
            if ($before['identity'] === null || in_array(false, $before['routing'], true)) {
                throw new CorruptHistoryException();
            }
            $issue = 'publication';
            $count = $this->source($kind, $id, $before, $budget);
            $budget->checkTime();
            $status = $count === 0 ? 'observed_empty' : 'verified';
        } catch (HistoryReadLimitException) {
            $status = 'limited';
        } catch (CorruptHistoryException) {
            $status = 'unverified';
        } catch (Throwable) {
            $status = 'unverified';
            $issue = 'unavailable';
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($kind, $id)) {
                    $status = 'changed';
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                if ($status !== 'changed') {
                    $status = 'limited';
                }
            } catch (Throwable) {
                $status = 'unverified';
                $issue = 'unavailable';
            }
        }
        return new CatalogProjectionCheck(
            $kind,
            $id,
            $status,
            $budget->eventsRead(),
            $status === 'verified' ? $count : null,
            $status === 'unverified' ? [$issue] : []
        );
    }

    private function source(string $kind, EntityId $id, array $before, HistoryReadBudget $budget): int
    {
        $aggregate = match ($kind) {
            'program' => ProgramPublication::root(new ProgramPublicationId($id->value)),
            'group' => CourseGroupPublication::root(new CourseGroupPublicationId($id->value)),
            'curriculum' => RequirementsPublication::root(new RequirementsPublicationId($id->value)),
        };
        $stream = new StreamReference($aggregate->aggregateId(), $this->type($kind));
        $seen = $years = [];
        $latest = null;
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $aggregate->acceptHistorical($stored->event);
                $payload = $stored->event->payload();
                if (isset($seen[$payload['version_id']])) {
                    throw new CorruptHistoryException();
                }
                $seen[$payload['version_id']] = true;
                if ($kind === 'program') {
                    $year = $payload['academic_year_id'];
                    $previous = $years[$year] ?? null;
                    if (
                        $payload['previous_catalog_id'] !== ($previous['version_id'] ?? null)
                        || ($previous !== null && $payload['catalog_revision'] <= $previous['catalog_revision'])
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $years[$year] = $payload;
                    $expected = ['program_id' => $id->value, 'academic_year_id' => $year,
                        'academic_year_code' => $payload['academic_year_code'],
                        'catalog_revision' => $payload['catalog_revision'],
                        'stream_version' => $stored->event->playhead(),
                        'definition' => ProgramDefinition::fromArray($payload['definition'])->describe(),
                        'specializations' => $payload['specializations']];
                    $actual = new SqlProgramDefinitionRepository($this->sql)->retained(
                        new EntityId($payload['version_id'])
                    );
                    if (ProtectedEventCodec::canonical($actual) !== ProtectedEventCodec::canonical($expected)) {
                        throw new CorruptHistoryException();
                    }
                }
                $latest = $payload;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $head = $before['head'];
        if (
            count($head) > 1 || ($head === [] ? 0 : filter_var($head[0]['version'], FILTER_VALIDATE_INT))
            !== $aggregate->playhead()
        ) {
            throw new CorruptHistoryException();
        }
        if ($kind === 'program') {
            $this->programPointers($before, $seen, $years);
        } else {
            $row = $before['identity'];
            if (
                filter_var($row['publication_position'], FILTER_VALIDATE_INT) !== $aggregate->playhead()
                || $row['published_version_id'] !== ($latest['version_id'] ?? null)
                || ($kind === 'group' && $row['published_name'] !== ($latest['definition']['name'] ?? null))
            ) {
                throw new CorruptHistoryException();
            }
            if ($kind === 'curriculum') {
                $aggregate->assertScope(
                    $row['program_id'],
                    $row['academic_year_id'],
                    $row['specialization_id'] ?? null
                );
            }
        }
        return count($seen);
    }

    private function programPointers(array $before, array $seen, array $years): void
    {
        $catalogs = [];
        foreach ($before['catalogs'] as $row) {
            $catalogs[$row['id']] = true;
            $current = ($years[$row['year_id']]['version_id'] ?? null) === $row['id'];
            if (
                !in_array(filter_var($row['is_current'], FILTER_VALIDATE_INT), [0, 1], true)
                || !in_array(filter_var($row['published'], FILTER_VALIDATE_INT), [0, 1], true)
                || (int) $row['is_current'] !== (int) $current
                || ((int) $row['published'] === 1 && !isset($seen[$row['id']]))
            ) {
                throw new CorruptHistoryException();
            }
            if (isset($seen[$row['id']]) && (int) $row['published'] !== 1) {
                throw new CorruptHistoryException();
            }
        }
        if (array_diff_key($seen, $catalogs) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (['details', 'sites', 'ownership', 'specializations'] as $key) {
            foreach ($before[$key] as $row) {
                if (!isset($seen[$key === 'sites' ? $row['catalog_id'] : $row['id']])) {
                    throw new CorruptHistoryException();
                }
            }
        }
    }

    private function type(string $kind): string
    {
        return match ($kind) {
            'program' => 'program_publication',
            'group' => 'course_group_publication',
            'curriculum' => 'degree_audit_requirements',
            default => throw new InvalidArgumentException('Choose a program, course group or curriculum.'),
        };
    }

    private function identity(string $kind, EntityId $id): ?array
    {
        $this->type($kind);
        $q = $this->sql->quote(...);
        $tables = match ($kind) {
            'program' => ['sis_programs'],
            'group' => ['sis_course_groups'],
            'curriculum' => ['sis_degree_audit_templates', 'sis_specialization_audit_templates'],
        };
        $columns = match ($kind) {
            'program' => ['id'],
            'group' => ['id', 'published_version_id', 'publication_position', 'published_name'],
            'curriculum' => ['id', 'program_id', 'academic_year_id', 'published_version_id', 'publication_position'],
        };
        $found = null;
        foreach ($tables as $table) {
            $fields = [...$columns, ...($table === 'sis_specialization_audit_templates' ? ['specialization_id'] : [])];
            $rows = $this->sql->fetch('SELECT ' . implode(', ', array_map($q, $fields)) . ' FROM ' . $q($table)
                . ' WHERE ' . $q('id') . ' = ?', [$id->value], 2);
            if (count($rows) > 1 || ($found !== null && $rows !== [])) {
                throw new CorruptHistoryException();
            }
            $found = $rows[0] ?? $found;
        }
        return $found;
    }

    private function boundary(string $kind, EntityId $id): array
    {
        $row = $this->identity($kind, $id);
        $result = ['identity' => $row, 'head' => $this->sql->rows('sis_history_streams', [
            'aggregate_id' => $id->value, 'stream_type' => $this->type($kind)], 2), 'routing' => []];
        if ($kind === 'curriculum' && $row !== null) {
            foreach (
                ['program_id' => 'sis_programs', 'academic_year_id' => 'sis_academic_years',
                'specialization_id' => 'sis_program_specializations'] as $field => $table
            ) {
                if (isset($row[$field])) {
                    $criteria = ['id' => $row[$field]];
                    if ($field === 'specialization_id') {
                        $criteria['program_id'] = $row['program_id'];
                    }
                    $result['routing'][$field] = $this->sql->rows($table, $criteria, 2) !== [];
                }
            }
        }
        if ($kind === 'program') {
            $result['catalogs'] = $this->sql->rows('sis_program_catalogs', ['program_id' => $id->value], 201);
            $tables = ['details' => 'sis_program_catalog_details', 'ownership' => 'sis_program_catalog_ownership',
                'specializations' => 'sis_program_specialization_contexts'];
            foreach ($tables as $key => $table) {
                $result[$key] = $this->sql->rows($table, ['program_id' => $id->value], 201);
            }
            $q = $this->sql->quote(...);
            $result['sites'] = $this->sql->fetch('SELECT * FROM ' . $q('sis_program_catalog_sites')
                . ' WHERE ' . $q('catalog_id') . ' IN (SELECT ' . $q('id') . ' FROM ' . $q('sis_program_catalogs')
                . ' WHERE ' . $q('program_id') . ' = ?)', [$id->value], 201);
        }
        foreach ($result as $key => &$rows) {
            if (in_array($key, ['identity', 'routing'], true)) {
                continue;
            }
            if (count($rows) > 200) {
                throw new HistoryReadLimitException();
            }
            usort($rows, static fn(array $a, array $b): int => strcmp(
                $a['id'] ?? $a['aggregate_id'],
                $b['id'] ?? $b['aggregate_id']
            ));
        }
        unset($rows);
        if (strlen(serialize($result)) > 4_194_304) {
            throw new HistoryReadLimitException();
        }
        return $result;
    }
}
