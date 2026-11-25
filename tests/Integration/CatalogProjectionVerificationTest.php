<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\CatalogVerificationFixture;

final class CatalogProjectionVerificationTest extends TestCase
{
    private function fixture(bool $select = true): CatalogVerificationFixture
    {
        require_once dirname(__DIR__) . '/Support/CatalogVerificationFixture.php';
        return new CatalogVerificationFixture(select: $select);
    }

    public function testCourseAndSelectedSectionVerifyWithWritesProhibitedAndNoBusinessValues(): void
    {
        $f = $this->fixture();
        $before = $f->facts();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        foreach (['course' => $f->course, 'section' => $f->section] as $kind => $id) {
            $result = $f->verifier()->$kind($id)->describe();
            self::assertSame('verified', $result['status']);
            self::assertSame(1, $result['records_checked']);
            self::assertSame($kind === 'course' ? 1 : 4, $result['events_checked']);
            self::assertSame($before, $f->facts());
            foreach (['definition', 'reason', 'faculty', 'title', 'capacity', 'fee', 'USD', 'password'] as $value) {
                self::assertStringNotContainsString($value, json_encode($result, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testDraftEditsAndLaterPublicationsDoNotReinterpretRetainedFacts(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->sql->update('sis_courses', ['title' => 'Fictional later draft'], ['id' => $f->course->value]);
        $s->publishCourse($f->course);
        $s->sql->update('sis_sections', ['capacity' => 50], ['id' => $f->section->value]);
        $service = $f->publications();
        $service->publish($f->section, $service->review(
            $f->section,
            '2026-08-24',
            '2026-12-18',
            'Fictional later publication.'
        )['review_token']);
        $before = $f->facts();
        self::assertSame('verified', $f->verifier()->course($f->course)->status);
        $result = $f->verifier()->section($f->section);
        self::assertSame('verified', $result->status);
        self::assertSame(2, $result->publications);
        self::assertSame($before, $f->facts());
    }

    public static function damage(): array
    {
        return array_map(static fn(string $v): array => [$v], ['course_cache', 'course_subject', 'section_cache',
            'section_classes', 'course_binding', 'offering_binding', 'publication_head', 'event', 'orphan_cache']);
    }

    #[DataProvider('damage')]
    public function testDamagedReferencesNeverProducePartialSuccess(string $damage): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        match ($damage) {
            'course_cache' => $s->sql->update('sis_course_versions', ['title' => 'Fictional damaged projection'],
                ['id' => $f->courseVersion->value]),
            'course_subject' => $s->sql->execute(
                'DELETE FROM sis_course_version_classes WHERE id = ?',
                [$f->courseVersion->value]
            ),
            'section_cache' => $s->sql->update(
                'sis_section_versions',
                ['capacity' => 99], ['id' => $f->sectionVersion->value]
            ),
            'section_classes' => $s->sql->execute(
                'DELETE FROM sis_section_version_classes WHERE id = ?',
                [$f->sectionVersion->value]
            ),
            'course_binding' => $s->sql->execute(
                'DELETE FROM sis_section_course_versions WHERE id = ?',
                [$f->section->value]
            ),
            'offering_binding' => $s->sql->execute(
                'DELETE FROM sis_section_offering_versions WHERE id = ?',
                [$f->section->value]
            ),
            'publication_head' => $s->sql->update('sis_history_streams', ['version' => 2],
                ['stream_type' => 'section_publication', 'aggregate_id' => $f->section->value]),
            'event' => $s->sql->execute(
                'UPDATE sis_history_events SET sealed_event = ? WHERE stream_id = ?',
                ['PRIVATE invalid source', $s->sql->one('sis_history_streams', ['stream_type' => 'section_publication',
                'aggregate_id' => $f->section->value])['id']]
            ),
            'orphan_cache' => $s->sql->update(
                'sis_course_versions',
                ['course_id' => $s->section()->courseId->value],
                ['id' => $f->courseVersion->value]
            ),
        };
        $before = $f->facts();
        $result = $f->verifier()->section($f->section)->describe();
        self::assertSame('unverified', $result['status']);
        self::assertNull($result['records_checked']);
        self::assertSame($before, $f->facts());
    }

    public function testReplayLimitsMissingRecordsAndEnclosingTransactionsRemainExplicit(): void
    {
        $f = $this->fixture();
        $limited = $f->verifier()->section($f->section, 3);
        self::assertSame('limited', $limited->status);
        self::assertNull($limited->publications);
        self::assertSame('verified', $f->verifier()->section($f->section, 4)->status);
        self::assertSame('unverified', $f->verifier()->course(EntityId::generate())->status);
        $f->sis->transactions->run(function () use ($f): void {
            self::assertSame('unverified', $f->verifier()->course($f->course)->status);
        });
    }

    public function testUnselectedAndUnpublishedSectionsKeepExactLimitsAndHonestEmptyResults(): void
    {
        $f = $this->fixture(false);
        $before = $f->facts();
        self::assertSame('verified', $f->verifier()->section($f->section, 3)->status);
        $draft = $f->sis->section();
        foreach (['course' => $draft->courseId, 'section' => $draft->id] as $kind => $id) {
            $result = $f->verifier()->$kind($id)->describe();
            self::assertSame('observed_empty', $result['status']);
            self::assertSame(0, $result['events_checked']);
            self::assertNull($result['records_checked']);
        }
        self::assertSame($before, $f->facts());
    }

    public function testMultiplePagesShareOneAllowanceAcrossAllFourSectionSources(): void
    {
        $f = $this->fixture();
        for ($index = 0; $index < 24; $index++) {
            $f->sis->publishCourse($f->course);
        }
        $before = $f->facts();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        self::assertSame('verified', $f->verifier()->section($f->section, 28)->status);
        self::assertSame(28, $f->verifier()->section($f->section, 28)->events);
        self::assertSame('limited', $f->verifier()->section($f->section, 27)->status);
        self::assertSame($before, $f->facts());
    }

    public function testConcurrentProjectionDriftIsChangedAndUnavailableExceptionsStayPrivate(): void
    {
        $f = $this->fixture();
        $real = $f->history();
        $changed = false;
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->expects(self::never())->method('append');
        $history->method('page')->willReturnCallback(function ($stream, $cursor, $limit) use ($real, $f, &$changed) {
            $page = $real->page($stream, $cursor, $limit);
            if (!$changed) {
                $changed = true;
                $f->sis->sql->update(
                    'sis_course_versions',
                    ['title' => 'Fictional concurrent projection'],
                    ['id' => $f->courseVersion->value]
                );
            }
            return $page;
        });
        $result = $f->verifier($history)->section($f->section);
        self::assertSame('changed', $result->status);
        self::assertNull($result->publications);
        self::assertSame([], $result->issues);
        $history = $this->createStub(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->method('page')->willThrowException(new \RuntimeException('PRIVATE provider failure.'));
        $result = $f->verifier($history)->course($f->course)->describe();
        self::assertSame('unverified', $result['status']);
        self::assertSame(['unavailable'], $result['issues']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function testOversizedProjectionInventoryIsLimitedWithoutPartialCountsOrWrites(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $row = $s->sql->one('sis_course_versions', ['id' => $f->courseVersion->value]);
        for ($index = 0; $index < 200; $index++) {
            $s->sql->insert('sis_course_versions', array_replace($row, ['id' => EntityId::generate()->value]));
        }
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $f->verifier()->course($f->course)->describe();
        self::assertSame('limited', $result['status']);
        self::assertNull($result['records_checked']);
        self::assertSame(0, $result['events_checked']);
        self::assertSame($before, $f->facts());
    }
}
