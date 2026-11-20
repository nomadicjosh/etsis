<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Gradebook\GradeDefinition;
use Domain\Registration\Service\DropPolicyService;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class SectionDropPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function fixture(): SisFixture
    {
        $sis = new SisFixture();
        $sis->legacyGrade(new GradeDefinition(EntityId::generate(), 'W', 0, false, false));
        return $sis;
    }
    private function publish(SisFixture $sis, EntityId $section, ?string $grace, bool $inherit = false): array
    {
        $review = $sis->dropPolicies->review(
            $sis->term,
            $sis->dropPolicies->show($sis->term, $section)['version'],
            new DropWindows([['through' => null, 'grade_code' => 'W']]),
            'Fictional section policy approval.',
            $section,
            $grace,
            $inherit
        );
        return $sis->dropPolicies->commit($sis->term, $review['review_token'], $section);
    }
    public function testDefaultInheritanceIsReadOnlyAndOverrideIsAnIndependentRetainedDefinition(): void
    {
        $sis = $this->fixture();
        $section = $sis->section(20, 0);
        $before = \EventStoreFixture::historyDigest($sis->sql);
        $show = $sis->dropPolicies->show($sis->term, $section->id);
        self::assertTrue($show['uses_term_policy']);
        self::assertSame(-1, $show['version']);
        self::assertSame('2026-08-15', $show['grace_ends_on']);
        self::assertSame($before, \EventStoreFixture::historyDigest($sis->sql));
        $saved = $this->publish($sis, $section->id, '2026-08-09');
        $show = $sis->dropPolicies->show($sis->term, $section->id);
        self::assertFalse($show['uses_term_policy']);
        self::assertSame('2026-08-09', $show['grace_ends_on']);
        self::assertSame($section->id->value, $show['published']['section_id']);
        self::assertSame([], $sis->sql->rows('sis_term_drop_policies'));
        $stream = (new \Infrastructure\EventSourcing\HistoricalSchemaRegistry())->stream(
            'section_drop_policy',
            $section->id->value,
        );
        $events = iterator_to_array(\EventStoreFixture::framework($sis->connection->queryBuilder())
            ->getAggregateHistoryFor($stream->aggregateId));
        self::assertCount(1, $events);
        self::assertSame($saved['publication_id'], $events[0]->payload()['publication_id']);
        $outcome = $sis->dropPolicies->outcome($sis->term, '2026-08-10', section: $section->id);
        self::assertFalse($outcome['delete_records']);
        self::assertSame($saved['publication_id'], $outcome['policy_reference']['publication_id']);
        self::assertSame($section->id->value, $outcome['policy_reference']['section_id']);
    }
    public function testMixedSectionPoliciesUseTheirOwnTranscriptAndChargeEffectsInOneDropPlan(): void
    {
        $sis = $this->fixture();
        $student = $sis->student()->id;
        $early = $sis->section(20, 1000);
        $late = $sis->section(20, 2000);
        $sis->service->register($student, $early->id, true);
        $sis->service->register($student, $late->id, true);
        $this->publish($sis, $late->id, '2026-08-09');
        $plan = new RegistrationPlanSelection($student, $early->id, $late->id);
        $review = $sis->drops->reviewPlan($plan, true);
        $rows = array_column($review['courses'], null, 'section_id');
        self::assertTrue($rows[$early->id->value]['outcome']['delete_records']);
        self::assertFalse($rows[$late->id->value]['outcome']['delete_records']);
        $saved = $sis->drops->commitPlan(
            $plan,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame(1, $saved['removed']);
        self::assertSame(1, $saved['withdrawn']);
        self::assertSame(2000, $sis->sql->rows('sis_account_balances')[0]['balance_minor']);
        $credit = $sis->sql->rows('sis_academic_credits')[0];
        self::assertSame('W', $credit['status']);
        self::assertSame(0, $credit['completed_milli']);
        self::assertSame($saved, $sis->drops->commitPlan(
            $plan,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['review_token'],
            true
        ));
    }
    public function testReturnToInheritanceFollowsLaterTermPoliciesAndPreservesOriginalReceipts(): void
    {
        $sis = $this->fixture();
        $section = $sis->section(20, 0);
        $this->publish($sis, $section->id, '2026-08-09');
        $review = $sis->dropPolicies->review(
            $sis->term,
            0,
            new DropWindows([['through' => null, 'grade_code' => 'W']]),
            'Fictional return to term policy.',
            $section->id,
            inherit: true
        );
        $saved = $sis->dropPolicies->commit($sis->term, $review['review_token'], $section->id);
        self::assertTrue($sis->dropPolicies->show($sis->term, $section->id)['uses_term_policy']);
        $sis->sql->update('sis_terms', ['add_drop_ends_on' => '2026-08-19'], ['id' => $sis->term->value]);
        $term = $sis->dropPolicies->review(
            $sis->term,
            -1,
            new DropWindows([['through' => null, 'grade_code' => 'W']]),
            'Fictional revised term deadline.'
        );
        $publication = $sis->dropPolicies->commit($sis->term, $term['review_token']);
        $outcome = $sis->dropPolicies->outcome($sis->term, '2026-08-18', section: $section->id);
        self::assertTrue($outcome['delete_records']);
        self::assertArrayNotHasKey('section_id', $outcome['policy_reference']);
        self::assertSame($publication['publication_id'], $outcome['policy_reference']['publication_id']);
        self::assertSame($saved, $sis->dropPolicies->commit($sis->term, $review['review_token'], $section->id));
        self::assertCount(2, $sis->dropPolicies->history($sis->term, section: $section->id)['items']);
    }
    public function testChangedOverrideInvalidatesPendingDropsWithoutRewritingEarlierDecisions(): void
    {
        $sis = $this->fixture();
        $student = $sis->student()->id;
        $section = $sis->section(20, 1000);
        $sis->service->register($student, $section->id, true);
        $review = $sis->drops->review($student, $section->id);
        $this->publish($sis, $section->id, '2026-08-09');
        try {
            $sis->drops->commit(
                $student,
                $section->id,
                new EntityId($review['enrollment_id']),
                $review['version'],
                new EntityId($review['operation_id']),
                $review['revision'],
                $review['effective_on']
            );
            self::fail('An earlier term-policy drop used a newly published section override.');
        } catch (ConcurrentModificationException) {
            self::assertNotNull($sis->registration->enrollment($student, $section->id));
        }
        $saved = $sis->drops->drop($student, $section->id);
        self::assertSame('W', $saved->status->value);
        $events = \EventStoreFixture::historyDigest($sis->sql);
        $this->publish($sis, $section->id, '2026-08-25');
        self::assertSame('W', $sis->registration->enrollment($student, $section->id)->status->value);
        self::assertSame(1000, $sis->sql->rows('sis_account_balances')[0]['balance_minor']);
        self::assertNotSame($events, \EventStoreFixture::historyDigest($sis->sql));
    }
    public function testInheritedResetReviewRejectsChangedParentAndAnotherSectionCannotUseItsProof(): void
    {
        $sis = $this->fixture();
        $section = $sis->section(20, 0);
        $other = $sis->section(20, 0);
        $review = $sis->dropPolicies->review(
            $sis->term,
            -1,
            new DropWindows([['through' => null, 'grade_code' => 'W']]),
            'Fictional inheritance review.',
            $section->id,
            inherit: true
        );
        try {
            $sis->dropPolicies->commit($sis->term, $review['review_token'], $other->id);
            self::fail('A section used another section\'s policy review.');
        } catch (\Domain\Shared\Exception\ValidationException) {
            self::assertSame([], $sis->sql->rows('sis_section_drop_policies'));
        }
        $sis->sql->update('sis_terms', ['add_drop_ends_on' => '2026-08-20'], ['id' => $sis->term->value]);
        $this->expectException(ConcurrentModificationException::class);
        $sis->dropPolicies->commit($sis->term, $review['review_token'], $section->id);
    }
    public function testMissingKnownSectionProjectionBlocksDropWithoutRepair(): void
    {
        $sis = $this->fixture();
        $section = $sis->section(20, 0);
        $this->publish($sis, $section->id, '2026-08-09');
        $sis->sql->execute('DELETE FROM sis_section_drop_policies WHERE section_id = ?', [$section->id->value]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $sis->dropPolicies->outcome($sis->term, '2026-08-10', section: $section->id);
    }
    public function testSectionReplacementUsesTheSourcesOverrideRatherThanTheTermDeadline(): void
    {
        $sis = $this->fixture();
        $student = $sis->student()->id;
        $source = $sis->section(20, 1000);
        $sis->service->register($student, $source->id, true);
        $this->publish($sis, $source->id, '2026-08-20');
        $row = $sis->sql->one('sis_sections', ['id' => $source->id->value]);
        $row['id'] = EntityId::generate()->value;
        $row['code'] .= '.B';
        $sis->sql->insert('sis_sections', $row);
        $sis->clock->time = $sis->clock->time->modify('+7 days');
        $selection = new \Domain\Registration\ValueObject\SectionChangeSelection(
            $student,
            $source->id,
            new EntityId($row['id'])
        );
        $review = $sis->sectionChanges()->review($selection, true);
        self::assertTrue($review['can_confirm']);
        $saved = $sis->sectionChanges()->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame($row['id'], $saved['to_section_id']);
    }
    public function testPortableMigrationCreatesOnlyAReconstructibleProjectionWithNoHistoryConversion(): void
    {
        $sis = $this->fixture();
        $created = null;
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->method('create')->willReturnCallback(static function (
            string $name,
            callable $callback,
        ) use (&$created): void {
            $created = new \Qubus\Expressive\Schema\CreateTable($name);
            $callback($created);
        });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $migration = new \CreateSisSectionDropPolicies('section-policy-compiler');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'fixture')]));
        $before = \EventStoreFixture::historyDigest($sis->sql);
        $migration->up();
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"',
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $sql = json_encode((new $class($dialect))->create($created), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('ssdp_section_fk', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |INSERT |UPDATE |DELETE /i', $sql);
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($sis->sql));
    }
    public function testFinalAuditRechecksCalendarExpiryAndAuthorityBeforeCommitting(): void
    {
        foreach (['calendar', 'expired', 'future', 'authority'] as $case) {
            $sis = $this->fixture();
            $section = $sis->section(20, 0);
            $audit = new class ($sis, $case) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(private SisFixture $sis, private string $case)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->case === 'calendar') {
                        $this->sis->sql->update(
                            'sis_terms',
                            ['ends_on' => '2026-12-25'],
                            ['id' => $this->sis->term->value],
                        );
                    } elseif ($this->case === 'authority') {
                        $this->sis->access->staff = false;
                    } else {
                        $this->sis->clock->time = $this->sis->clock->time->modify(
                            $this->case === 'expired' ? '+21 minutes' : '-1 minute',
                        );
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $service = new DropPolicyService(
                new \Infrastructure\Persistence\Repository\SqlDropPolicyRepository(
                    $sis->sql,
                    $sis->gradeCatalog()
                ),
                $sis->access,
                $sis->transactions,
                $audit,
                $sis->academicCredits(),
                \EventStoreFixture::history($sis->connection->queryBuilder()),
                $sis->dropCipher,
                $sis->clock
            );
            $review = $service->review(
                $sis->term,
                -1,
                new DropWindows([['through' => null, 'grade_code' => 'W']]),
                'Fictional section deadline review.',
                $section->id,
                '2026-08-09'
            );
            $before = \EventStoreFixture::historyDigest($sis->sql);
            try {
                $service->commit($sis->term, $review['review_token'], $section->id);
                self::fail('A changed calendar left a partial publication.');
            } catch (ConcurrentModificationException | \Domain\Shared\Exception\AccessDeniedException) {
                self::assertSame($before, \EventStoreFixture::historyDigest($sis->sql));
                self::assertSame([], $sis->sql->rows('sis_section_drop_policies'));
                self::assertSame('2026-12-20', $sis->sql->one('sis_terms', ['id' => $sis->term->value])['ends_on']);
            }
        }
    }
    public function testHiatusUsesTheSectionsRetainedLateDropPolicy(): void
    {
        require_once dirname(__DIR__) . '/Support/HiatusFixture.php';
        $fixture = new \HiatusFixture();
        $sis = $fixture->sis;
        $sis->legacyGrade(new GradeDefinition(EntityId::generate(), 'W', 0, false, false));
        $student = $sis->student()->id;
        $section = $sis->section(20, 1000);
        $sis->service->register($student, $section->id, true);
        $this->publish($sis, $section->id, '2026-08-09');
        $dates = new \Domain\Shared\ValueObject\DateRange(new \DateTimeImmutable('2026-08-10'));
        $review = $fixture->workflow()->review($student, \Domain\Student\Enum\HiatusCode::Withdrawal, $dates);
        self::assertSame('2026-08-09', $review['courses'][0]['drop_policy']['grace_ends_on']);
        $hiatus = new \Domain\Student\StudentHiatus(
            new EntityId($review['operation_id']),
            $student,
            \Domain\Student\Enum\HiatusCode::Withdrawal,
            $dates
        );
        $saved = $fixture->workflow()->commit(
            $hiatus,
            'Fictional reviewed institutional withdrawal.',
            new \Domain\Student\ValueObject\HiatusImpact(true, $review['revision'], [], $section->id),
            $review['review_token']
        );
        self::assertSame('W', $saved['impact']['course_changes'][0]['grade_code']);
        self::assertSame(1000, $sis->sql->rows('sis_account_balances')[0]['balance_minor']);
    }
}
