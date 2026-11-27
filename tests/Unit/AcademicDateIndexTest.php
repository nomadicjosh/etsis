<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Reporting\ValueObject\ReportQuery;
use Infrastructure\Reporting\AcademicReportSchema;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AcademicDateIndexTest extends TestCase
{
    public function testDateIndexCompilesForFiveDialectsWithoutChangingBusinessHistory(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261126000005_CreateSisAcademicDateIndex.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_academic_date_index');
            \CreateSisAcademicDateIndex::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (
                ['student_id', 'enrollment_id', 'academic_on', 'completed_on', 'removed',
                'position', 'head_hash', 'sealed_state', 'sadi_student_completed'] as $field
            ) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression(
                '/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT|FOREIGN KEY/i',
                $sql,
            );
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }

    public function testHiddenDatesAndNamedBranchesRetainProjectionDependencyAndStudentGrants(): void
    {
        $base = ['dataset' => 'students', 'columns' => [
            ['field' => 'student_id', 'label' => 'Student', 'aggregate' => 'none'],
        ]];
        $filter = ['condition' => 'AND', 'rules' => [
            ['id' => 'recorded_institutional_completed_on', 'operator' => 'is_not_null'],
        ]];
        $cases = [
            $base + ['filters' => $filter],
            $base + ['join_filters' => ['credit_institutional_dates' => $filter]],
            $base + ['record_selection' => ['academic_credit' => [
                'date_field' => 'recorded_institutional_completed_on', 'direction' => 'latest', 'ties' => 'all',
            ]]],
            $base + ['aliases' => ['copy1' => ['relation' => 'academic_credit', 'label' => 'Earlier work']],
                'record_selection' => ['copy1' => ['date_field' => 'copy1__recorded_institutional_completed_on',
                    'direction' => 'earliest', 'ties' => 'one']]],
        ];
        foreach ($cases as $definition) {
            $query = new ReportQuery($definition, AcademicReportSchema::datasets()['students']);
            self::assertTrue($query->usesProjection('academic_dates'));
            self::assertContains('sis:student:read', $query->permissions());
        }
        self::assertFalse(new ReportQuery($base, AcademicReportSchema::datasets()['students'])
            ->usesProjection('academic_dates'));
        $base['filters'] = ['condition' => 'AND', 'rules' => [
            ['id' => 'recorded_transfer_completed_on', 'operator' => 'is_not_null'],
        ]];
        self::assertFalse(new ReportQuery($base, AcademicReportSchema::datasets()['students'])
            ->usesProjection('academic_dates'));
    }
}
