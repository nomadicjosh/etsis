<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Projection\SqlCurriculumProjectionVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurriculumProjectionVerificationTest extends TestCase
{
    private function history(\SisFixture $s): HistoricalEventStore
    {
        return \EventStoreFixture::history($s->connection->queryBuilder());
    }

    private function fixture(): \ReusableGroupFixture
    {
        require_once dirname(__DIR__) . '/Support/ReusableGroupFixture.php';
        $f = new \ReusableGroupFixture();
        $f->saveDegree();
        $f->publishDegree();
        return $f;
    }

    private function program(): array
    {
        require_once dirname(__DIR__) . '/Support/ProgramPublicationFixture.php';
        $f = new \ProgramPublicationFixture();
        $year = new EntityId($f->calendar->year(2024)['id']);
        $review = $f->service->review($f->sis->program, $year, 'Fictional program approval.');
        $f->service->publish($f->sis->program, $review['review_token']);
        return [$f, $year];
    }

    public function testProgramCohortProjectionsVerifyReadOnlyAndRetainEarlierPublications(): void
    {
        [$f, $year] = $this->program();
        $s = $f->sis;
        $s->sql->update('sis_programs', ['title' => 'Fictional revised draft'], ['id' => $s->program->value]);
        $f->service->publish($s->program, $f->service->review(
            $s->program,
            $year,
            'Fictional later approval.'
        )['review_token']);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $history = $this->history($s);
        $result = new SqlCurriculumProjectionVerifier($s->sql, $history)->verify('program', $s->program);
        self::assertSame('verified', $result->status);
        self::assertSame(2, $result->publications);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        self::assertStringNotContainsString('Fictional', json_encode($result->describe()));
    }

    public static function programDamage(): array
    {
        return array_map(static fn(string $damage): array => [$damage], [
            'definition', 'choices', 'missing_definition', 'missing_choices', 'current', 'published',
            'orphan_catalog', 'head', 'payload',
        ]);
    }

    #[DataProvider('programDamage')]
    public function testProgramProjectionDamageCannotBeCertified(string $damage): void
    {
        [$f] = $this->program();
        $s = $f->sis;
        $catalog = $s->sql->rows('sis_program_catalogs', ['program_id' => $s->program->value], 1)[0];
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        match ($damage) {
            'definition' => $s->sql->update('sis_program_catalog_details', ['description' => 'Fictional damaged text'],
                ['id' => $catalog['id']]),
            'choices' => $s->sql->update('sis_program_specialization_contexts', ['snapshot_json' => '{}'],
                ['id' => $catalog['id']]),
            'missing_definition' => $s->sql->execute(
                'DELETE FROM sis_program_catalog_details WHERE id = ?',
                [$catalog['id']]
            ),
            'missing_choices' => $s->sql->execute(
                'DELETE FROM sis_program_specialization_contexts WHERE id = ?',
                [$catalog['id']]
            ),
            'current' => $s->sql->update('sis_program_catalogs', ['is_current' => 0], ['id' => $catalog['id']]),
            'published' => $s->sql->update('sis_program_catalogs', ['published' => 0], ['id' => $catalog['id']]),
            'orphan_catalog' => $s->sql->insert('sis_program_catalogs', array_replace($catalog,
                ['id' => EntityId::generate()->value, 'revision' => 10, 'is_current' => 0])),
            'head' => $s->sql->execute('DELETE FROM sis_history_streams WHERE aggregate_id = ?', [$s->program->value]),
            'payload' => $s->sql->execute(
                'UPDATE sis_history_events SET sealed_event = ? WHERE stream_id = ?',
                ['PRIVATE invalid envelope', $s->sql->one('sis_history_streams', [
                'stream_type' => 'program_publication',
                'aggregate_id' => $s->program->value])['id']]
            ),
        };
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = new SqlCurriculumProjectionVerifier($s->sql, $this->history($s))->verify('program', $s->program);
        self::assertSame('unverified', $result->status);
        self::assertNull($result->publications);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        self::assertStringNotContainsString('PRIVATE', json_encode($result->describe()));
    }

    public function testEarlierYearPointersRemainCurrentWithinTheirOwnYear(): void
    {
        [$f, $earlier] = $this->program();
        $later = new EntityId($f->calendar->year(2025)['id']);
        foreach ([$later, $earlier] as $year) {
            $f->service->publish($f->sis->program, $f->service->review(
                $f->sis->program,
                $year,
                'Fictional additional cohort approval.'
            )['review_token']);
        }
        $v = new SqlCurriculumProjectionVerifier($f->sis->sql, $this->history($f->sis));
        self::assertSame('limited', $v->verify('program', $f->sis->program, 2)->status);
        $result = $v->verify('program', $f->sis->program, 3);
        self::assertSame('verified', $result->status);
        self::assertSame(3, $result->publications);
    }

    public function testDraftOnlyRecordsRemainHonestEmptyObservationsAndMissingRecordsFail(): void
    {
        require_once dirname(__DIR__) . '/Support/ReusableGroupFixture.php';
        $f = new \ReusableGroupFixture();
        $group = EntityId::generate();
        $f->groups->create($group, \Domain\DegreeAudit\ValueObject\CourseGroupDefinition::blank('Fictional draft'));
        $v = new SqlCurriculumProjectionVerifier($f->sis->sql, $f->history);
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        foreach (['program' => $f->sis->program, 'group' => $group, 'curriculum' => $f->degree] as $kind => $id) {
            $result = $v->verify($kind, $id);
            self::assertSame('observed_empty', $result->status);
            self::assertSame(0, $result->events);
            self::assertNull($result->publications);
            self::assertSame('unverified', $v->verify($kind, EntityId::generate())->status);
        }
    }

    public function testOfferedChoicesAndComponentRequirementRoutingStayDistinct(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentSpecializationRequirementsFixture.php';
        $f = new \StudentSpecializationRequirementsFixture();
        $s = $f->r->sis;
        $v = new SqlCurriculumProjectionVerifier($s->sql, $f->r->history);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        self::assertSame('verified', $v->verify('program', $s->program)->status);
        foreach ($f->plans as $plan) {
            $id = new EntityId($plan['id']);
            self::assertSame('verified', $v->verify('curriculum', $id)->status);
            self::assertSame($plan['specialization_id'], $v->context('curriculum', $id)['specialization_id']);
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        $s->connection->pdo->exec('PRAGMA query_only = OFF');
        $first = array_values($f->plans)[0];
        $choice = $s->sql->one('sis_program_specializations', ['id' => $first['specialization_id']]);
        $other = EntityId::generate();
        $s->sql->insert('sis_program_specializations', array_replace(
            $choice,
            ['id' => $other->value, 'code' => 'FICT-OTHER']
        ));
        $s->sql->update(
            'sis_specialization_audit_templates',
            ['specialization_id' => $other->value],
            ['id' => $first['id']]
        );
        self::assertSame('unverified', $v->verify('curriculum', new EntityId($first['id']))->status);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testOversizedProgramInventoryStopsBeforeReplay(): void
    {
        [$f] = $this->program();
        $s = $f->sis;
        $row = $s->sql->rows('sis_program_catalogs', ['program_id' => $s->program->value], 1)[0];
        for ($i = 2; $i <= 201; ++$i) {
            $s->sql->insert('sis_program_catalogs', array_replace($row, ['id' => EntityId::generate()->value,
                'revision' => $i, 'published' => 0, 'is_current' => 0]));
        }
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = new SqlCurriculumProjectionVerifier($s->sql, $this->history($s))->verify('program', $s->program);
        self::assertSame('limited', $result->status);
        self::assertSame(0, $result->events);
        self::assertNull($result->publications);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testGroupAndDegreePointersVerifyWithoutReinterpretingDrafts(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->sql->update(
            'sis_course_groups',
            ['name' => 'Fictional working title', 'draft_json' => '{}'],
            ['id' => $f->group->value]
        );
        $s->sql->update('sis_degree_audit_templates', ['draft_json' => '{}'], ['id' => $f->degree->value]);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        foreach (['group' => $f->group, 'curriculum' => $f->degree] as $kind => $id) {
            $result = new SqlCurriculumProjectionVerifier($s->sql, $f->history)->verify($kind, $id);
            self::assertSame('verified', $result->status);
            self::assertSame(1, $result->publications);
            self::assertSame(1, $result->events);
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public static function damage(): array
    {
        return array_map(static fn(string $damage): array => [$damage], [
            'group_pointer', 'group_name', 'group_position', 'degree_pointer', 'degree_position',
            'degree_program', 'degree_year', 'missing_program', 'missing_year', 'head', 'payload',
        ]);
    }

    #[DataProvider('damage')]
    public function testDamagedPointersScopeAndProtectedHistoryNeverReturnPartialSuccess(string $damage): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $kind = str_starts_with($damage, 'group') ? 'group' : 'curriculum';
        $id = $kind === 'group' ? $f->group : $f->degree;
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        match ($damage) {
            'group_pointer' => $s->sql->update(
                'sis_course_groups',
                ['published_version_id' => EntityId::generate()->value],
                ['id' => $id->value]
            ),
            'group_name' => $s->sql->update('sis_course_groups', ['published_name' => 'Fictional damaged name'],
                ['id' => $id->value]),
            'group_position' => $s->sql->update('sis_course_groups', ['publication_position' => 0],
                ['id' => $id->value]),
            'degree_pointer' => $s->sql->update('sis_degree_audit_templates', ['published_version_id' => null],
                ['id' => $id->value]),
            'degree_position' => $s->sql->update('sis_degree_audit_templates', ['publication_position' => 0],
                ['id' => $id->value]),
            'degree_program' => $s->sql->update(
                'sis_degree_audit_templates',
                ['program_id' => EntityId::generate()->value],
                ['id' => $id->value]
            ),
            'degree_year' => $s->sql->update(
                'sis_degree_audit_templates',
                ['academic_year_id' => EntityId::generate()->value],
                ['id' => $id->value]
            ),
            'missing_program' => $s->sql->execute('DELETE FROM sis_programs WHERE id = ?', [$s->program->value]),
            'missing_year' => $s->sql->execute('DELETE FROM sis_academic_years WHERE id = ?', [$f->year->value]),
            'head' => $s->sql->execute('DELETE FROM sis_history_streams WHERE aggregate_id = ?', [$id->value]),
            'payload' => $s->sql->execute(
                'UPDATE sis_history_events SET sealed_event = ? WHERE stream_id = ?',
                ['damaged fictional envelope', $s->sql->one('sis_history_streams', [
                'aggregate_id' => $id->value,
                'stream_type' => 'degree_audit_requirements'])['id']]
            ),
        };
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = new SqlCurriculumProjectionVerifier($s->sql, $f->history)->verify($kind, $id);
        self::assertSame('unverified', $result->status);
        self::assertNull($result->publications);
        self::assertNotEmpty($result->issues);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testBudgetsAndTransactionRefusalDoNotClaimPartialVerification(): void
    {
        $f = $this->fixture();
        $f->publishGroup();
        $verifier = new SqlCurriculumProjectionVerifier($f->sis->sql, $f->history);
        self::assertSame('limited', $verifier->verify('group', $f->group, 1)->status);
        self::assertSame('verified', $verifier->verify('group', $f->group, 2)->status);
        $f->sis->transactions->run(function () use ($verifier, $f): void {
            $result = $verifier->verify('group', $f->group);
            self::assertSame('unverified', $result->status);
            self::assertSame(['unavailable'], $result->issues);
            self::assertSame(0, $result->events);
        });
    }

    public function testConcurrentPointerChangesAndPrivateProviderFailuresRemainDistinct(): void
    {
        $f = $this->fixture();
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('append');
        $history->method('page')->willReturnCallback(function ($stream, $cursor, $limit) use ($f) {
            $page = $f->history->page($stream, $cursor, $limit);
            $f->sis->sql->update(
                'sis_course_groups',
                ['published_name' => 'Fictional concurrent change'],
                ['id' => $f->group->value]
            );
            return $page;
        });
        $result = new SqlCurriculumProjectionVerifier($f->sis->sql, $history)->verify('group', $f->group);
        self::assertSame('changed', $result->status);
        self::assertNull($result->publications);
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willThrowException(new \RuntimeException('PRIVATE upstream details'));
        $result = new SqlCurriculumProjectionVerifier($f->sis->sql, $history)->verify('group', $f->group);
        self::assertSame(['unavailable'], $result->issues);
        self::assertStringNotContainsString('PRIVATE', json_encode($result->describe()));
    }
}
