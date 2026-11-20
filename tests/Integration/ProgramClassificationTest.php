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
        self::assertSame(
            $earlier + ['school' => null, 'field_of_study' => null, 'campus' => null],
            $current->describe(),
        );
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

    public static function historicalClassificationShapes(): array
    {
        return ['before school fields' => [false], 'school fields without campus' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historicalClassificationShapes')]
    public function testEarlierRetainedProjectionsDoNotInferSchoolsFromCurrentAssignments(bool $schoolFields): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $review = $p->service->review($f->program, new EntityId($year['id']), 'Fictional earlier publication');
        $values = $review['definition'];
        unset($values['classifications']['campus']);
        if (!$schoolFields) {
            unset($values['classifications']['school'], $values['classifications']['field_of_study']);
        }
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
        $row = $f->sql->one('sis_program_catalog_details', ['id' => $operation->value]);
        self::assertSame(0, (int) $row['campus_recorded']);
        $f->sql->update('sis_program_catalog_details', ['campus_code' => 'INVENTED'], ['id' => $operation->value]);
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
    private function campusDraft(ProgramPublicationFixture $p, ?array $campus): array
    {
        $draft = $p->service->draft($p->sis->program);
        return $p->service->save(
            $p->sis->program,
            $draft["definition"]["code"],
            $draft["definition"]["title"],
            $draft["definition"]["description"],
            null,
            null,
            null,
            $draft["revision"],
            campus: $campus === null ? null : new EntityId($campus["id"]),
        );
    }

    public function testProgramCampusRetainsCohortLabelsAndReportsCurrentLocationSeparately(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $campus = $this->reference($p, ClassificationKind::Campus, "MAIN", "Fictional Main Campus");
        $before = $f->sql->rows("sis_history_events");
        $saved = $this->campusDraft($p, $campus);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $review = $p->service->review(
            $f->program,
            new EntityId($year["id"]),
            "Fictional program campus approval.",
        );
        $receipt = $p->service->publish($f->program, $review["review_token"]);
        $version = new EntityId($receipt["operation_id"]);
        $row = $f->sql->one("sis_program_catalog_details", ["id" => $version->value]);
        self::assertSame(1, (int) $row["campus_recorded"]);
        self::assertSame("MAIN", $row["campus_code"]);
        $person = $f->legacyPerson($f->user(), "Fictional", "Student");
        $application = $f->legacyApplication($person->id, $f->program, new EntityId($year["terms"][0]["id"]));
        $f->legacyAdmissionDecision($application->id, true);
        $f->matriculate($application->id);
        $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "NORTH",
            "Fictional North Campus",
            false,
            $campus["revision"],
        );
        self::assertSame(
            $saved["definition"],
            new SqlProgramDefinitionRepository($f->sql)->retained($version)["definition"],
        );
        self::assertSame($receipt, $p->service->publish($f->program, $review["review_token"]));
        $events = $f->sql->rows("sis_history_events");
        $source = new SqlRelationalReportSource($f->sql);
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            [
                "dataset" => "programs",
                "columns" => array_map(
                    static fn(string $field): array => [
                        "field" => $field,
                        "label" => $field,
                        "aggregate" => "none",
                    ],
                    [
                        "program_campus_code",
                        "program_campus_title",
                        "program_campus_recorded",
                        "current_program_campus_code",
                        "current_program_campus_title",
                    ],
                ),
                "filters" => null,
                "having" => null,
                "sort" => [],
            ],
            $source->datasets()["programs"],
        );
        $rows = $source->execute($query, 0);
        self::assertCount(1, $rows, "The location joins must not multiply student-program rows.");
        self::assertSame("MAIN", $rows[0]["c0"]);
        self::assertSame("Fictional Main Campus", $rows[0]["c1"]);
        self::assertSame(1, (int) $rows[0]["c2"]);
        self::assertSame("NORTH", $rows[0]["c3"]);
        self::assertSame("Fictional North Campus", $rows[0]["c4"]);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $this->campusDraft($p, null);
        $next = $p->service->review(
            $f->program,
            new EntityId($year["id"]),
            "Fictional online program context.",
        );
        $p->service->publish($f->program, $next["review_token"]);
        self::assertSame(
            "MAIN",
            $source->execute($query, 0)[0]["c0"],
            "A new cohort publication cannot relabel this student.",
        );
    }

    public function testCampusMustUseTheSharedCategoryAndRetiredChoicesCannotBeNewlyAssigned(): void
    {
        $p = new ProgramPublicationFixture();
        $campus = $this->reference($p, ClassificationKind::Campus, "MAIN", "Main Campus");
        $school = $this->reference($p, ClassificationKind::School, "SCI", "School of Science");
        try {
            $this->campusDraft($p, $school);
            self::fail("A school was accepted as a campus.");
        } catch (ValidationException) {
            self::assertSame([], $p->sis->sql->rows("sis_program_details"));
        }
        $this->campusDraft($p, $campus);
        $retired = $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "MAIN",
            "Retired Main Campus",
            false,
            $campus["revision"],
        );
        $this->campusDraft($p, $retired);
        $this->campusDraft($p, null);
        $this->expectException(ValidationException::class);
        $this->campusDraft($p, $retired);
    }

    public function testOldProgramShapesDoNotInventCampusAndPartialCampusPayloadsAreRejected(): void
    {
        $old = [
            "department" => null,
            "academic_level" => null,
            "award" => null,
            "school" => null,
            "field_of_study" => null,
        ];
        $value = ProgramClassifications::fromArray($old);
        self::assertFalse($value->campusRecorded);
        self::assertSame($old, $value->describe());
        $current = ProgramClassifications::fromArray($old + ["campus" => null]);
        self::assertTrue($current->campusRecorded);
        self::assertNull($current->campus);
        foreach (
            [
                $old + ["campus" => "MAIN"],
                array_diff_key($old, ["school" => true]) + ["campus" => null],
                array_slice($old, 0, 3, true) + ["campus" => null],
                $old + ["location" => null],
            ] as $bad
        ) {
            try {
                ProgramClassifications::fromArray($bad);
                self::fail("An incomplete or untyped location was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testStaleCampusRenameCannotPublishAndExplicitBlankIsRetained(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $campus = $this->reference($p, ClassificationKind::Campus, "MAIN", "Main Campus");
        $this->campusDraft($p, $campus);
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional campus review.");
        $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "MAIN",
            "Renamed Campus",
            true,
            $campus["revision"],
        );
        $events = $f->sql->rows("sis_history_events");
        try {
            $p->service->publish($f->program, $review["review_token"]);
            self::fail("A stale campus was published.");
        } catch (ConcurrentModificationException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
        $this->campusDraft($p, null);
        $review = $p->service->review(
            $f->program,
            new EntityId($year["id"]),
            "Fictional program without campus.",
        );
        $receipt = $p->service->publish($f->program, $review["review_token"]);
        $retained = $f->sql->one("sis_program_catalog_details", ["id" => $receipt["operation_id"]]);
        self::assertSame(1, (int) $retained["campus_recorded"]);
        self::assertNull($retained["campus_id"]);
        self::assertArrayHasKey(
            "campus",
            new SqlProgramDefinitionRepository($f->sql)->retained(new EntityId($receipt["operation_id"]))[
                "definition"
            ]["classifications"],
        );
    }

    public function testPublicationFinalChecksRollBackChangedCampusSourceAuthorityExpiryAndProjection(): void
    {
        foreach (["reference", "authority", "expiry", "projection", "audit"] as $failure) {
            $p = new ProgramPublicationFixture();
            $f = $p->sis;
            $year = $p->calendar->year(2024);
            $campus = $this->reference($p, ClassificationKind::Campus, "MAIN", "Main Campus");
            $this->campusDraft($p, $campus);
            $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional publication.");
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($f, $failure, $campus, $review): void {
                    if ($failure === "reference") {
                        $f->sql->update(
                            "sis_catalog_classifications",
                            ["title" => "Changed during acceptance"],
                            ["id" => $campus["id"]],
                        );
                    } elseif ($failure === "authority") {
                        $f->access->staff = false;
                    } elseif ($failure === "expiry") {
                        $f->clock->time = $f->clock->time->modify("+20 minutes");
                    } elseif ($failure === "projection") {
                        $f->sql->update(
                            "sis_program_catalog_details",
                            ["campus_code" => "UNRECORDED"],
                            ["id" => $review["operation_id"]],
                        );
                    } else {
                        throw new \RuntimeException("Fictional required-audit failure.");
                    }
                });
            $tables = [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_program_catalogs",
                "sis_program_catalog_details",
                "sis_program_specialization_contexts",
                "sis_catalog_classifications",
                "sis_audit",
            ];
            $before = array_map($f->sql->rows(...), $tables);
            try {
                $p->publisher(audit: $audit)->publish($f->program, $review["review_token"]);
                self::fail("A failed publication committed.");
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                self::assertNotSame("A failed publication committed.", $e->getMessage());
                self::assertSame($before, array_map($f->sql->rows(...), $tables));
            }
        }
    }

    public function testRecordedCampusProjectionCannotBeReplacedFromCurrentSetup(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $year = $p->calendar->year(2024);
        $campus = $this->reference($p, ClassificationKind::Campus, "MAIN", "Main Campus");
        $this->campusDraft($p, $campus);
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional publication.");
        $receipt = $p->service->publish($f->program, $review["review_token"]);
        $f->sql->update(
            "sis_program_catalog_details",
            ["campus_id" => null],
            ["id" => $receipt["operation_id"]],
        );
        $this->expectException(CorruptHistoryException::class);
        new SqlProgramDefinitionRepository($f->sql)->retained(new EntityId($receipt["operation_id"]));
    }

    public function testCampusMigrationPreservesExistingRowsWithPortableNullableReferencesAndNoBackfill(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $f->sql->insert("sis_program_details", [
            "id" => $f->program->value,
            "description" => "Existing draft",
        ]);
        $before = $f->sql->one("sis_program_details", ["id" => $f->program->value]);
        // Running the migration again is harmless and does not overwrite the existing draft.
        $migration = new \AddSisProgramCampuses("program-classifications");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($f->connection, "fixture"),
            ]),
        );
        $migration->up();
        self::assertSame($before, $f->sql->one("sis_program_details", ["id" => $f->program->value]));
        $foreign = $f->connection->pdo
            ->query("PRAGMA foreign_key_list(sis_program_details)")
            ->fetchAll(\PDO::FETCH_ASSOC);
        self::assertContains("campus_id", array_column($foreign, "from"));
        self::assertSame([], $f->sql->rows("sis_history_events"));
        $changes = [];
        $schema = $this->createStub(Schema::class);
        $schema->method("getColumns")->willReturn(["id"]);
        $schema
            ->method("alter")
            ->willReturnCallback(static function (string $table, callable $callback) use (&$changes): void {
                $definition = new AlterTable($table);
                $callback($definition);
                $changes[] = $definition;
            });
        $connection = $this->createStub(Connection::class);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method("getAttribute")->willReturn("mysql");
        $connection->method(PropertyHook::get("pdo"))->willReturn($pdo);
        $connection->method("getSchema")->willReturn($schema);
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($connection, "fixture"),
            ]),
        );
        $migration->up();
        self::assertCount(5, $changes);
        foreach (["MySQL", "PostgreSQL", "SQLServer", "Oracle"] as $driver) {
            $dialect = $this->createStub(Connection::class);
            $dialect
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $commands = [];
            foreach ($changes as $change) {
                array_push($commands, ...new $class($dialect)->alter($change));
            }
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertStringContainsString("campus_id", $sql);
            self::assertStringContainsString("campus_code", $sql);
            self::assertStringContainsString("campus_recorded", $sql);
            self::assertDoesNotMatchRegularExpression("/DROP |DELETE |UPDATE |INSERT /i", $sql);
        }
    }
}
