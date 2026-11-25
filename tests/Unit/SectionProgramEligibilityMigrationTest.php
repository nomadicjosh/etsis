<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
use Qubus\Expressive\Schema\CreateTable;

final class SectionProgramEligibilityMigrationTest extends TestCase
{
    public function testProgramReferencesCompileAcrossAllFiveSupportedDialects(): void
    {
        require_once dirname(__DIR__, 2) .
        "/database/migrations/20261124000009_AddSisSectionProgramEligibility.php";
        $connection = $this->createStub(Connection::class);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $table = new CreateTable("sis_section_program_eligibility");
            \AddSisSectionProgramEligibility::define($table);
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("PRIMARY KEY", $sql);
            self::assertStringContainsString("sis_sections", $sql);
            self::assertStringContainsString("sis_programs", $sql);
            self::assertStringNotContainsString("COLLATE", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|INSERT INTO|AUTO_INCREMENT/i",
                $sql,
            );
        }
    }

    public function testRerunsDoNotChangeExistingPublicationsOrMembershipIdentities(): void
    {
        require_once dirname(__DIR__) . "/Support/OfferingCreditFixture.php";
        $f = new \Tests\Support\OfferingCreditFixture();
        $f->bind();
        $f->select($publication = $f->publish());
        $s = $f->sis;
        $id = \Domain\Shared\ValueObject\EntityId::generate()->value;
        $s->sql->insert("sis_section_program_eligibility", [
            "id" => $id,
            "section_id" => $f->section->id->value,
            "program_id" => $s->program->value,
        ]);
        $tables = [
            "sis_section_versions",
            "sis_section_program_eligibility",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
        ];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \AddSisSectionProgramEligibility("20261124000009");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new DbalMigrationAdapter($s->connection, "test_migrations"),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        self::assertSame(
            "[]",
            $s->sql->one("sis_section_versions", ["id" => $publication->value])["eligible_program_ids"],
        );
    }
}
