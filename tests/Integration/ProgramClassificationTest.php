<?php

declare(strict_types=1);

namespace Tests\Integration;

use ArrayObject;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ClassificationService;
use Domain\Catalog\ValueObject\ProgramClassifications;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use ProgramPublicationFixture;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\AlterTable;

final class ProgramClassificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/ProgramPublicationFixture.php';
    }

    private function reference(
        ProgramPublicationFixture $p,
        ClassificationKind $kind,
        string $code,
        string $title,
    ): array {
        return $p->classifications->save($kind, null, $code, $title, true, null);
    }

    private function assign(ProgramPublicationFixture $p, ?array $school = null, ?array $field = null): array
    {
        $draft = $p->service->draft($p->sis->program);
        return $p->service->save(
            $p->sis->program,
            $draft['definition']['code'],
            $draft['definition']['title'],
            $draft['definition']['description'],
            null,
            null,
            null,
            $draft['revision'],
            school: $school === null ? null : new EntityId($school['id']),
            fieldOfStudy: $field === null ? null : new EntityId($field['id'])
        );
    }

    public function testSchoolAndFieldAreRetainedWithTheCohortAndReportJoinAfterReferenceEdits(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $school = $this->reference($p, ClassificationKind::School, 'ARTS', 'College of Arts');
        $field = $this->reference($p, ClassificationKind::FieldOfStudy, '11.0701', 'Fictional Computer Science');
        $before = $f->sql->rows('sis_history_events');
        $saved = $this->assign($p, $school, $field);
        self::assertSame($before, $f->sql->rows('sis_history_events'), 'Reference and draft setup remain CRUD.');
        $review = $p->service->review($f->program, new EntityId($year['id']), 'Fictional classification approval');
        $receipt = $p->service->publish($f->program, $review['review_token']);
        $version = new EntityId($receipt['operation_id']);
        $row = $f->sql->one('sis_program_catalog_details', ['id' => $version->value]);
        self::assertSame(1, (int) $row['school_field_recorded']);
        self::assertSame('11.0701', $row['field_of_study_code']);
        $person = $f->legacyPerson($f->user(), 'Fictional', 'Student');
        $application = $f->legacyApplication($person->id, $f->program, new EntityId($year['terms'][0]['id']));
        $f->legacyAdmissionDecision($application->id, true);
        $f->matriculate($application->id);
        foreach ([$school, $field] as $reference) {
            $p->classifications->save(
                ClassificationKind::from($reference['kind']),
                new EntityId($reference['id']),
                $reference['code'],
                'Renamed current label',
                false,
                $reference['revision']
            );
        }
        $history = $f->sql->rows('sis_history_events');
        $retained = new SqlProgramDefinitionRepository($f->sql)->retained($version);
        self::assertSame($saved['definition'], $retained['definition']);
        self::assertSame($receipt, $p->service->publish($f->program, $review['review_token']));
        $source = new SqlRelationalReportSource($f->sql);
        $query = new \Domain\Reporting\ValueObject\ReportQuery([
            'dataset' => 'programs',
            'columns' => array_map(
                static fn(string $field): array =>
                ['field' => $field, 'label' => $field, 'aggregate' => 'none'],
                ['program_school_code', 'program_school_title', 'program_field_of_study_code',
                'program_field_of_study_title',
                'program_school_field_recorded']
            ),
            'filters' => null, 'having' => null, 'sort' => [],
        ], $source->datasets()['programs']);
        $rows = $source->execute($query, 0);
        self::assertCount(1, $rows);
        self::assertSame('ARTS', $rows[0]['c0']);
        self::assertSame('College of Arts', $rows[0]['c1']);
        self::assertSame('11.0701', $rows[0]['c2']);
        self::assertSame('Fictional Computer Science', $rows[0]['c3']);
        self::assertSame(1, (int) $rows[0]['c4']);
        self::assertSame($history, $f->sql->rows('sis_history_events'));
    }

    public function testWrongCategoriesRetirementAndChangedReferencesCannotBypassDraftOrPublicationReview(): void
    {
        $p = new ProgramPublicationFixture();
        $school = $this->reference($p, ClassificationKind::School, 'ARTS', 'College of Arts');
        $field = $this->reference($p, ClassificationKind::FieldOfStudy, '11.0701', 'Computer Science');
        try {
            $this->assign($p, $field, $school);
            self::fail('A field-of-study definition was accepted as a school.');
        } catch (ValidationException) {
            self::assertSame([], $p->sis->sql->rows('sis_program_details'));
        }
        $this->assign($p, $school, $field);
        $year = $p->calendar->year(2024);
        $review = $p->service->review($p->sis->program, new EntityId($year['id']), 'Fictional approval');
        $retired = $p->classifications->save(
            ClassificationKind::School,
            new EntityId($school['id']),
            'ARTS',
            'Renamed school',
            false,
            $school['revision']
        );
        try {
            $p->service->publish($p->sis->program, $review['review_token']);
            self::fail('A changed classification was published from an earlier review.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $p->sis->sql->rows('sis_program_catalog_details'));
        }
        $this->assign($p, $retired, $field); // An existing retired assignment may remain.
        $this->assign($p, null, $field);
        $this->expectException(ValidationException::class);
        $this->assign($p, $retired, $field); // It cannot be newly selected after clearing.
    }

    public function testEarlierClassificationShapeKeepsUnknownFieldsAndRejectsPartialOrInventedSnapshots(): void
    {
        $earlier = ['department' => null, 'academic_level' => null, 'award' => null];
        $old = ProgramClassifications::fromArray($earlier);
        self::assertFalse($old->schoolAndFieldRecorded);
        self::assertSame($earlier, $old->describe());
        $current = new ProgramClassifications();
        self::assertTrue($current->schoolAndFieldRecorded);
        self::assertSame($earlier + ['school' => null, 'field_of_study' => null], $current->describe());
        foreach (
            [$earlier + ['school' => null], $earlier + ['field_of_study' => null],
            $earlier + ['unrecognized' => null]] as $fields
        ) {
            try {
                ProgramClassifications::fromArray($fields);
                self::fail('An incomplete classification publication was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEarlierRetainedProjectionsDoNotInferSchoolsFromCurrentAssignments(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $review = $p->service->review($f->program, new EntityId($year['id']), 'Fictional earlier publication');
        $values = $review['definition'];
        unset($values['classifications']['school'], $values['classifications']['field_of_study']);
        $operation = new EntityId($review['operation_id']);
        $payload = ['version_id' => $operation->value] + array_intersect_key($review, array_flip([
            'previous_version_id', 'academic_year_id', 'academic_year_code', 'catalog_revision', 'previous_catalog_id',
            'specializations',
        ])) + ['definition' => $values];
        $event = \Domain\Catalog\Event\ProgramDefinitionWasPublished::occur(
            new \Domain\Catalog\ValueObject\ProgramPublicationId($f->program->value),
            $payload,
            ['__aggregate_type' => 'program_publication', 'schema_version' => 1]
        )->withPlayhead(1);
        $store = \EventStoreFixture::history($f->connection->queryBuilder());
        $f->transactions->run(function () use ($f, $store, $operation, $event, $values, $year, $review): void {
            $store->append(
                new \Domain\Shared\EventSourcing\StreamReference($event->aggregateId(), 'program_publication'),
                $operation,
                0,
                new \Domain\Shared\EventSourcing\EventContext(
                    $f->access->actor,
                    $f->clock->now(),
                    $operation,
                    reason: 'Fictional original publication'
                ),
                [$event]
            );
            $f->sql->insert('sis_program_catalogs', ['id' => $operation->value, 'program_id' => $f->program->value,
                'year_id' => $year['id'], 'revision' => 1, 'published' => 1, 'is_current' => 1,
                'program_code' => $values['code'], 'program_title' => $values['title']]);
            new SqlProgramDefinitionRepository($f->sql)->retain(
                $f->program,
                $operation,
                $year['code'],
                1,
                ProgramDefinition::fromArray($values),
                new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot($review['specializations'])
            );
        });
        $before = $f->sql->rows('sis_history_events');
        $school = $this->reference($p, ClassificationKind::School, 'SCI', 'Current school');
        $this->assign($p, $school);
        self::assertEquals($values, $p->service->versions($f->program)['items'][0]['definition']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $f->sql->update('sis_program_catalog_details', ['school_id' => $school['id']], ['id' => $operation->value]);
        $this->expectException(CorruptHistoryException::class);
        new SqlProgramDefinitionRepository($f->sql)->retained($operation);
    }

    public function testReferenceAndDraftWritesRollBackAfterAuthorityLossOrRequiredAuditFailure(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f): void {
            $f->access->staff = false;
            $f->access->grants = [];
        });
        $references = new ClassificationService(
            new SqlClassificationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit
        );
        try {
            $references->save(ClassificationKind::School, null, 'DENIED', 'Fictional school', true, null);
            self::fail('A reference write committed after its actor lost authority.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_catalog_classifications', ['kind' => 'school']));
        }
        $f->access->staff = true;
        $school = $this->reference($p, ClassificationKind::School, 'ARTS', 'College of Arts');
        $f->connection->pdo->exec("CREATE TRIGGER program_class_fail BEFORE INSERT ON sis_audit "
            . "WHEN NEW.action = 'program.details_saved' BEGIN SELECT RAISE(ABORT, 'fictional audit failure'); END");
        try {
            $this->assign($p, $school);
            self::fail('Program draft choices survived a failed required audit.');
        } catch (\PDOException) {
            self::assertSame([], $f->sql->rows('sis_program_details'));
        }
    }

    public function testMigrationPreservesExistingRowsWithPortableNullableReferencesAndNoBackfill(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $f->sql->insert('sis_program_details', ['id' => $f->program->value, 'description' => 'Existing draft']);
        $before = $f->sql->one('sis_program_details', ['id' => $f->program->value]);
        // Running the migration again is harmless and does not overwrite the existing draft.
        $migration = new \AddSisProgramClassifications('program-classifications');
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($f->connection, 'fixture'),
        ]));
        $migration->up();
        self::assertSame($before, $f->sql->one('sis_program_details', ['id' => $f->program->value]));
        $foreign = $f->connection->pdo->query('PRAGMA foreign_key_list(sis_program_details)')
            ->fetchAll(\PDO::FETCH_ASSOC);
        self::assertContains('school_id', array_column($foreign, 'from'));
        self::assertContains('field_of_study_id', array_column($foreign, 'from'));
        self::assertSame([], $f->sql->rows('sis_history_events'));
        $changes = [];
        $schema = $this->createStub(Schema::class);
        $schema->method('getColumns')->willReturn(['id']);
        $schema->method('alter')->willReturnCallback(static function (
            string $table,
            callable $callback,
        ) use (&$changes): void {
            $definition = new AlterTable($table);
            $callback($definition);
            $changes[] = $definition;
        });
        $connection = $this->createStub(Connection::class);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('getSchema')->willReturn($schema);
        $migration->setObjectMap(new ArrayObject([
            'phpmig.adapter' => new DbalMigrationAdapter($connection, 'fixture'),
        ]));
        $migration->up();
        self::assertCount(9, $changes);
        foreach (['MySQL', 'PostgreSQL', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $commands = [];
            foreach ($changes as $change) {
                array_push($commands, ...new $class($dialect)->alter($change));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('school_id', $sql);
            self::assertStringContainsString('field_of_study_code', $sql);
            self::assertStringContainsString('school_field_recorded', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE |UPDATE |INSERT /i', $sql);
        }
    }
}
