<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema;
use Qubus\Expressive\Schema\CreateTable;

$registrationCompilerMigrations = dirname(__DIR__, 2) . "/database/migrations/";

require_once $registrationCompilerMigrations . "20261106000000_CreateSisAdmissionsTables.php";
require_once $registrationCompilerMigrations . "20261106000001_CreateSisStudentAccountsTable.php";
require_once $registrationCompilerMigrations . "20261106000002_CreateSisWorkflowTables.php";
require_once $registrationCompilerMigrations . "20261106000003_CreateSisBillingRulesTable.php";
require_once $registrationCompilerMigrations . "20261106000004_CreateSisPersonIdentifiersTable.php";

require_once $registrationCompilerMigrations . "20261106000005_CreateSisAccessTables.php";

require_once $registrationCompilerMigrations . "20261106000006_CreateSisWorkspaceTables.php";
require_once $registrationCompilerMigrations . "20261113000000_CreateSisCourseRules.php";
require_once $registrationCompilerMigrations . "20261113000001_CreateSisProgramSpecializations.php";
require_once $registrationCompilerMigrations . "20261113000002_CreateSisSpecializationPolicies.php";

require_once $registrationCompilerMigrations . "20261107000000_CreateCampusPortalTables.php";

require_once $registrationCompilerMigrations . "20261107000001_CreateSisRestrictionNotesTable.php";

require_once $registrationCompilerMigrations . "20261107000003_CreateSisRegistrationBatchesTable.php";

require_once $registrationCompilerMigrations . "20261107000004_CreateSisStudentHiatusTables.php";

require_once $registrationCompilerMigrations . "20261107000005_CreateSisCatalogOperationsTable.php";

require_once $registrationCompilerMigrations . "20261107000006_CreateSisFinancialReviewTables.php";

require_once $registrationCompilerMigrations . "20261107000007_CreateSisTranscriptDocumentsTable.php";

require_once $registrationCompilerMigrations . "20261107000008_CreateSisCatalogYearTables.php";

require_once $registrationCompilerMigrations . "20261107000009_CreateSisSectionDeliveryTable.php";

require_once $registrationCompilerMigrations . "20261107000010_CreateSisRelationalReportTables.php";

require_once $registrationCompilerMigrations . "20261107000011_CreateSisTransferAwardChangesTable.php";

require_once $registrationCompilerMigrations . "20261107000012_CreateSisCourseDropTables.php";

require_once $registrationCompilerMigrations . "20261107000013_CreateSisReportRequestTables.php";

require_once $registrationCompilerMigrations . "20261108000000_CreateSisOfferingTemplateTables.php";

require_once $registrationCompilerMigrations . "20261108000001_CreateSisAcademicCalendarTables.php";

require_once $registrationCompilerMigrations . "20261108000002_CreateSisHistoricalEventTables.php";

require_once $registrationCompilerMigrations . "20261108000003_CreateSisCourseDescriptionsTable.php";

require_once $registrationCompilerMigrations . "20261108000004_CreateSisCourseVersionBindings.php";

require_once $registrationCompilerMigrations . "20261108000005_CreateSisDegreeAuditTemplates.php";

require_once $registrationCompilerMigrations . "20261108000006_CreateSisCourseGroupLibrary.php";

require_once $registrationCompilerMigrations . "20261108000007_CreateSisStudentRequirements.php";

require_once $registrationCompilerMigrations . "20261109000000_CreateSisRequirementAdjustments.php";

require_once $registrationCompilerMigrations . "20261109000001_CreateSisGradingScales.php";

require_once $registrationCompilerMigrations . "20261109000003_CreateSisAcademicCreditStreams.php";

require_once $registrationCompilerMigrations . "20261109000004_CreateSisTransferCreditStreams.php";

require_once $registrationCompilerMigrations . "20261109000005_CreateSisRegistrationHistory.php";
require_once $registrationCompilerMigrations . "20261109000006_CreateSisStudentActivity.php";
require_once $registrationCompilerMigrations . "20261109000007_CreateSisProgramStatusStreams.php";
require_once $registrationCompilerMigrations . "20261109000008_CreateSisPrimaryProgramStreams.php";
require_once $registrationCompilerMigrations . "20261109000009_CreateSisAdmissionCorrectionPermission.php";

require_once $registrationCompilerMigrations . "20261109000010_CreateSisSectionVersions.php";

require_once $registrationCompilerMigrations . "20261110000001_CreateSisSectionOfferingSelections.php";

require_once $registrationCompilerMigrations . "20261110000002_AddSisOfferingSelectionPosition.php";

require_once $registrationCompilerMigrations . "20261110000003_CreateSisCourseSubjects.php";
require_once $registrationCompilerMigrations . "20261110000004_CreateSisCourseClassifications.php";
require_once $registrationCompilerMigrations . "20261110000005_CreateSisProgramDefinitions.php";
require_once $registrationCompilerMigrations . "20261111000006_CreateSisMoodleBindings.php";
require_once $registrationCompilerMigrations . "20261111000007_CreateSisMoodlePersonLinks.php";
require_once $registrationCompilerMigrations . "20261112000000_CreateSisMoodleAccountRequests.php";

require_once $registrationCompilerMigrations . "20261112000002_CreateSisLoginSessions.php";
require_once $registrationCompilerMigrations . "20261112000003_CreateSisMoodleLaunches.php";
require_once $registrationCompilerMigrations . "20261112000005_CreateSisMoodleAccountRecoveries.php";
require_once $registrationCompilerMigrations . "20261112000006_CreateSisMoodleSectionSync.php";
require_once $registrationCompilerMigrations . "20261112000007_CreateSisMoodleCourseRequests.php";
require_once $registrationCompilerMigrations . "20261112000008_CreateSisMoodleCategories.php";

final class MigrationCompilationTest extends TestCase
{
    public function testMoodleResolutionUpgradeAddsNullableEvidenceWithoutChangingOriginalFacts(): void
    {
        require_once dirname(__DIR__, 2) . "/database/migrations/20261112000004_CreateSisMoodleAccountResolutions.php";
        $definition = null;
        $schema = $this->createMock(Schema::class);
        $schema->expects(self::once())->method("alter")->willReturnCallback(
            static function (string $name, callable $callback) use (&$definition): void {
                self::assertSame("sis_moodle_account_requests", $name);
                $definition = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($definition);
            },
        );
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $migration = new CreateSisMoodleAccountResolutions("resolutions");
        $migration->setObjectMap(new ArrayObject([
            "phpmig.adapter" => new DbalMigrationAdapter($connection, "test_migrations"),
        ]));
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect->method("quoteIdentifier")->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $commands = new $class($dialect)->alter($definition);
            self::assertCount(2, $commands, $driver);
            $sql = json_encode($commands, JSON_THROW_ON_ERROR);
            self::assertStringContainsString("resolution_hash", $sql);
            self::assertStringContainsString("encrypted_resolution", $sql);
            self::assertStringNotContainsString("NOT NULL", strtoupper($sql));
            self::assertStringNotContainsString("DROP", strtoupper($sql));
        }
    }

    public function testMoodleOwnerLookupIndexCompilesForEverySupportedDialect(): void
    {
        $schema = $this->createMock(Schema::class);
        $definition = null;
        $schema
            ->expects(self::once())
            ->method("alter")
            ->willReturnCallback(static function (string $name, callable $callback) use (&$definition): void {
                self::assertSame("sis_persons", $name);
                $definition = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($definition);
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $migration = new CreateSisMoodleLaunches("launch-index");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "test_migrations")]),
        );
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            self::assertStringContainsString(
                "moodle_owner_person_idx",
                json_encode(new $class($dialect)->alter($definition), JSON_THROW_ON_ERROR),
            );
        }
    }

    public function testMoodleProvisioningLeaseUpgradePreservesRequestsAndCompilesAcrossDialects(): void
    {
        require_once dirname(__DIR__, 2) .
            "/database/migrations/20261112000001_CreateSisMoodleProvisioningLeases.php";
        $definition = null;
        $schema = $this->createMock(Schema::class);
        $schema
            ->expects(self::once())
            ->method("alter")
            ->willReturnCallback(static function (string $name, callable $callback) use (&$definition): void {
                $definition = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($definition);
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $migration = new CreateSisMoodleProvisioningLeases("leases");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "test_migrations")]),
        );
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $commands = new $class($dialect)->alter($definition);
            self::assertNotEmpty($commands);
            self::assertStringContainsString("lease_until", json_encode($commands, JSON_THROW_ON_ERROR));
            self::assertStringContainsString("attempts", json_encode($commands, JSON_THROW_ON_ERROR));
            self::assertStringContainsString("issue_code", json_encode($commands, JSON_THROW_ON_ERROR));
        }
    }
    public function testTransferReadIndexesCompileAcrossDialectsAndRollbackOnSqlite(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $fixture = new SisFixture();
        $migration = new CreateSisTransferReadIndexes("indexes");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($fixture->connection, "test_migrations"),
            ]),
        );
        $migration->down();
        $alters = [];
        $schema = $this->createMock(Schema::class);
        $schema
            ->expects(self::exactly(4))
            ->method("alter")
            ->willReturnCallback(static function (string $name, callable $callback) use (
                &$alters,
                $fixture,
            ): void {
                $definition = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($definition);
                $alters[] = $definition;
                $fixture->connection->getSchema()->alter($name, $callback);
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $migration = new CreateSisTransferReadIndexes("indexes");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "test_migrations")]),
        );
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect->method("quoteIdentifier")->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    "MySQL" => "`" . $name . "`",
                    "SQLServer" => "[" . $name . "]",
                    default => '"' . $name . '"',
                },
            );
            $compiler = new $class($dialect);
            foreach ($alters as $definition) {
                self::assertCount(
                    1,
                    $compiler->alter($definition),
                    $driver . " index creation must compile.",
                );
            }
        }
        $historyCases1 = [
            "sis_external_courses",
            "sis_transfer_equivalencies",
            "sis_transfer_awards",
            "sis_academic_credits",
        ];
        foreach ($historyCases1 as $table) {
            $indexes = $fixture->connection->pdo
                ->query("PRAGMA index_list(" . $table . ")")
                ->fetchAll(PDO::FETCH_ASSOC);
            self::assertNotEmpty(
                array_filter(
                    $indexes,
                    static fn(array $row): bool => in_array(
                        $row["name"],
                        [
                            "sis_external_page",
                            "sis_equivalency_page",
                            "sis_transfer_award_page",
                            "sis_student_credit_read",
                        ],
                        true,
                    ),
                ),
            );
        }
    }

    public function testRegistrationCountIndexesCompileAcrossDialectsAndSupportIndexedCounts(): void
    {
        $fixture = new SisFixture();
        foreach (["sis_section_seat_count", "sis_student_course_count"] as $name) {
            $fixture->connection->pdo->exec("DROP INDEX " . $name);
        }
        $alters = [];
        $schema = $this->createMock(Schema::class);
        $schema->method("hasTable")->willReturn(true);
        $schema
            ->expects(self::exactly(2))
            ->method("alter")
            ->willReturnCallback(static function (string $name, callable $callback) use (
                &$alters,
                $fixture,
            ): void {
                $t = new \Qubus\Expressive\Schema\AlterTable($name);
                $callback($t);
                $alters[] = $t;
                $fixture->connection->getSchema()->alter($name, $callback);
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($fixture->connection->queryBuilder());
        $migration = new CreateSisRegistrationHistory("index-check");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "test_migrations")]),
        );
        $migration->up();
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect->method("quoteIdentifier")->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    "MySQL" => "`" . $name . "`",
                    "SQLServer" => "[" . $name . "]",
                    default => '"' . $name . '"',
                },
            );
            $compiler = new $class($dialect);
            foreach ($alters as $t) {
                self::assertCount(1, $compiler->alter($t));
            }
        }
        $plan = $fixture->sql->fetch(
            "EXPLAIN QUERY PLAN SELECT COUNT(*) FROM sis_enrollments WHERE section_id = ? AND status IN (?, ?)",
            ["fictional", "A", "N"],
        );
        self::assertStringContainsString("sis_section_seat_count", json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function testNewTablesCompileWithEveryInstalledSchemaCompiler(): void
    {
        $tables = [];
        $live = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite::memory:",
        ]);
        $live->pdo->exec(
            "CREATE TABLE users (user_id VARCHAR(36) PRIMARY KEY, role VARCHAR(80), " .
                "first_name VARCHAR(191), last_name VARCHAR(191))",
        );
        $schema = $this->createMock(Schema::class);
        $schema
            ->expects(self::exactly(127))
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $callback) use (
                &$tables,
                $live,
            ): void {
                $table = new CreateTable($name);
                $callback($table);
                $tables[] = $table;
                $live->getSchema()->create($name, $callback);
            });
        $connection = $this->createStub(Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($live->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $name) => '"' . $name . '"');
        $adapter = new DbalMigrationAdapter($connection, "test_migrations");
        $historyCases2 = [
            new CreateSisAdmissionsTables("1"),
            new CreateSisStudentAccountsTable("2"),
            new CreateSisWorkflowTables("3"),
            new CreateSisBillingRulesTable("4"),
            new CreateSisPersonIdentifiersTable("5"),
            new CreateSisAccessTables("6"),
            new CreateSisWorkspaceTables("7"),
            new CreateCampusPortalTables("8"),
            new CreateSisRestrictionNotesTable("9"),
            new CreateSisRegistrationBatchesTable("10"),
            new CreateSisStudentHiatusTables("11"),
            new CreateSisCatalogOperationsTable("12"),
            new CreateSisFinancialReviewTables("13"),
            new CreateSisTranscriptDocumentsTable("14"),
            new CreateSisCatalogYearTables("15"),
            new CreateSisSectionDeliveryTable("16"),
            new CreateSisRelationalReportTables("17"),
            new CreateSisTransferAwardChangesTable("18"),
            new CreateSisCourseDropTables("19"),
            new CreateSisReportRequestTables("20"),
            new CreateSisOfferingTemplateTables("21"),
            new CreateSisAcademicCalendarTables("22"),
            new CreateSisHistoricalEventTables("23"),
            new CreateSisCourseDescriptionsTable("24"),
            new CreateSisCourseVersionBindings("25"),
            new CreateSisCourseSubjects("subjects"),
            new CreateSisCourseClassifications("classifications"),
            new CreateSisProgramDefinitions("programs"),
            new CreateSisMoodleBindings("moodle-bindings"),
            new CreateSisMoodlePersonLinks("moodle-person-links"),
            new CreateSisMoodleAccountRequests("moodle-account-requests"),
            new CreateSisLoginSessions("login-sessions"),
            new CreateSisMoodleLaunches("moodle-launches"),
            new CreateSisMoodleAccountRecoveries("moodle-account-recoveries"),
            new CreateSisMoodleSectionSync("moodle-section-sync"),
            new CreateSisMoodleCourseRequests("moodle-course-requests"),
            new CreateSisMoodleCategories("moodle-categories"),
            new CreateSisDegreeAuditTemplates("26"),
            new CreateSisCourseGroupLibrary("27"),
            new CreateSisStudentRequirements("28"),
            new CreateSisRequirementAdjustments("29"),
            new CreateSisGradingScales("30"),
            new CreateSisAcademicCreditStreams("31"),
            new CreateSisTransferCreditStreams("32"),
            new CreateSisRegistrationHistory("33"),
            new CreateSisStudentActivity("34"),
            new CreateSisProgramStatusStreams("35"),
            new CreateSisPrimaryProgramStreams("36"),
            new CreateSisAdmissionCorrectionPermission("37"),
            new CreateSisSectionVersions("38"),
            new CreateSisSectionOfferingSelections("39"),
            new CreateSisCourseRules("course-rules"),
            new CreateSisProgramSpecializations("program-specializations"),
            new CreateSisSpecializationPolicies("specialization-policies"),
        ];
        foreach ($historyCases2 as $migration) {
            $migration->setObjectMap(new ArrayObject(["phpmig.adapter" => $adapter]));
            $migration->up();
        }
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect->method("quoteIdentifier")->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    "MySQL" => "`" . $name . "`",
                    "SQLServer" => "[" . $name . "]",
                    default => '"' . $name . '"',
                },
            );
            $compiler = new $class($dialect);
            foreach ($tables as $table) {
                $commands = $compiler->create($table);
                self::assertNotEmpty($commands, $driver . " must compile " . $table->getTableName());
                if (
                    $driver === "MySQL" &&
                    in_array(
                        $table->getTableName(),
                        [
                            "sis_catalog_operations",
                            "sis_student_hiatus_actions",
                            "sis_financial_review_actions",
                        ],
                        true,
                    )
                ) {
                    self::assertStringContainsString("MEDIUMTEXT", $commands[0]["sql"]);
                }
                self::assertSame("string", $table->getColumns()["id"]->getType());
                self::assertSame(26, $table->getColumns()["id"]->get("length"));
            }
        }
    }
    public function testOfferingSelectionPositionUpgradePreservesExistingReferencesAndCompilesAcrossDialects(): void
    {
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            "driver" => "pdo_sqlite",
            "dsn" => "sqlite::memory:",
        ]);
        $connection->pdo->exec(
            "CREATE TABLE sis_section_offering_versions (id VARCHAR(26) PRIMARY KEY, publication_id VARCHAR(26) NOT NULL)",
        );
        $section = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $publication = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $statement = $connection->pdo->prepare(
            "INSERT INTO sis_section_offering_versions (id, publication_id) VALUES (?, ?)",
        );
        $statement->execute([$section, $publication]);
        $migration = new AddSisOfferingSelectionPosition("1");
        $migration->setObjectMap(
            new ArrayObject(["phpmig.adapter" => new DbalMigrationAdapter($connection, "test_migrations")]),
        );
        $migration->up();
        $row = $connection->pdo
            ->query("SELECT * FROM sis_section_offering_versions")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame($section, $row["id"]);
        self::assertSame($publication, $row["publication_id"]);
        self::assertSame(1, (int) $row["stream_version"]);
        $alter = new \Qubus\Expressive\Schema\AlterTable("sis_section_offering_versions");
        $alter->integer("stream_version")->notNull()->defaultValue(1);
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $dialect = $this->createStub(Connection::class);
            $dialect
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $sql = new $class($dialect)->alter($alter);
            self::assertNotEmpty($sql);
            self::assertStringContainsString("stream_version", json_encode($sql, JSON_THROW_ON_ERROR));
        }
        $this->expectException(LogicException::class);
        $migration->down();
    }
}
