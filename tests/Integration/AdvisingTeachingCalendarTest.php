<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\BookingPolicySnapshot;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingAvailabilityFixture;
use Tests\Support\RegistrationScheduleFixture;

final class AdvisingTeachingCalendarTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        require_once dirname(__DIR__) . '/Support/RegistrationScheduleFixture.php';
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        $f = new AdvisingAvailabilityFixture();
        $a = $f->appointments;
        $s = $a->sis;
        $catalog = new \CatalogWorkflowFixture($s);
        $base = $catalog->plan()->sections[0];
        $section = new Section(
            $base->id,
            $base->courseId,
            $base->termId,
            $a->advisor,
            'ADV.CLASS.01',
            20,
            false,
            true,
            new Money(0, 'USD'),
            DeliveryMode::Hybrid
        );
        $plan = new OfferingPlan($section->termId, $section);
        $offerings = $catalog->offerings();
        $review = $offerings->review($plan);
        $op = new EntityId($review['operation_id']);
        $offerings->save($op, $plan, $review['revision']);
        $offerings->publish($op, $catalog->publicationReview($offerings, $op)['review_token']);
        $version = new EntityId($s->sql->rows(
            'sis_section_offering_versions',
            ['id' => $section->id->value],
            1
        )[0]['publication_id']);
        return [$f, $section, $version];
    }

    private function publish(
        AdvisingAvailabilityFixture $f,
        Section $section,
        EntityId $offering,
        string $start = '17:00',
        string $end = '18:00'
    ): void {
        new RegistrationScheduleFixture($f->appointments->sis)->publish(
            $section,
            [['2026-08-11T' . $start . ':00Z', '2026-08-11T' . $end . ':00Z']],
            $offering
        );
    }

    private function policy(AdvisingAvailabilityFixture $f, string $mode = 'block'): void
    {
        $p = $f->appointments->policies();
        $r = $p->review(
            BookingPolicySnapshot::DEFAULTS + ['advisor_teaching_mode' => $mode],
            $p->snapshot()->revision,
            'Fictional advisor teaching rule.'
        );
        $p->save(new EntityId($r['id']), $r['rules'], $r['version'], $r['reason'], $r['review_token']);
    }

    private function rejects(callable $run, string $type = ValidationException::class): void
    {
        try {
            $run();
            self::fail('Expected a refused teaching overlap.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    public function testRetainedAdvisorTeachingBlocksBothSchedulingAndOwnerChoicesWithoutClassDisclosures(): void
    {
        [$f, $section, $offering] = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $this->publish($f, $section, $offering);
        $this->policy($f);
        $history = $s->sql->rows('sis_history_events');
        $this->rejects(fn() => $a->schedule());
        $window = new EntityId($f->offer()['id']);
        $s->access->actor = $a->owner;
        $choices = $a->service()->bookingChoices($a->student, $a->pursuit, windowId: $window);
        self::assertCount(2, $choices['slots']);
        self::assertSame('2026-08-11T11:00', $choices['slots'][0]['start']);
        self::assertStringNotContainsString($section->code, json_encode($choices));
        self::assertStringNotContainsString($a->advisor->value, json_encode($choices));
        $this->rejects(fn() => $a->service()->book(
            $a->student,
            $a->pursuit,
            EntityId::generate(),
            $window,
            0,
            '2026-08-11T10:00'
        ));
        self::assertSame('scheduled', $a->service()->book(
            $a->student,
            $a->pursuit,
            EntityId::generate(),
            $window,
            0,
            '2026-08-11T11:00'
        )['status']);
        self::assertSame($history, $s->sql->rows('sis_history_events'));
    }

    public function testWorkingFacultyEditsAndAccountRelinksDoNotEraseTheRecordedPersonTeaching(): void
    {
        [$f, $section, $offering] = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $this->publish($f, $section, $offering);
        $this->policy($f);
        $s->sql->update('sis_sections', ['faculty_user_id' => null], ['id' => $section->id->value]);
        $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value], ['id' => $a->host->value]);
        $this->rejects(fn() => $a->schedule());
        self::assertSame([], $s->sql->rows('sis_advising_appointments'));
    }

    public function testOriginalBookingAndRescheduleReceiptsSurviveLaterTeachingChanges(): void
    {
        [$f, $section, $offering] = $this->fixture();
        $a = $f->appointments;
        $this->policy($f);
        $id = EntityId::generate();
        $created = $a->schedule($id);
        $this->publish($f, $section, $offering);
        self::assertSame($created, $a->schedule($id));
        $this->rejects(fn() => $a->act($id, 'reschedule', time: $a->time()));
        $operation = EntityId::generate();
        $time = $a->time('2026-08-11T11:00', '2026-08-11T11:30');
        $run = fn() => $a->service()->act(
            $a->student,
            $a->pursuit,
            'staff',
            $id,
            $operation,
            'reschedule',
            0,
            $time,
            'Fictional moved appointment.'
        );
        $result = $run();
        $this->publish($f, $section, $offering, '18:00', '19:00');
        self::assertSame($result, $run());
    }

    public function testFinalTeachingPublicationDriftRollsBackTheAppointmentAndCoupledEvents(): void
    {
        [$f, $section, $offering] = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $this->policy($f);
        $before = $s->sql->rows('sis_history_events');
        $audit = $a->audit(function () use ($f, $section, $offering): void {
            $this->publish($f, $section, $offering);
        });
        $this->rejects(
            fn() => $a->service($audit)->schedule(
                $a->student,
                $a->pursuit,
                'staff',
                EntityId::generate(),
                $a->host,
                '2026-08-11T10:00',
                '2026-08-11T10:30',
                $s->clock->now()->getTimezone()->getName(),
                'Fictional teaching race.'
            ),
            ConcurrentModificationException::class
        );
        self::assertSame([], $s->sql->rows('sis_advising_appointments'));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testMissingPublishedProjectionCannotMakeAnAdvisorAppearFree(): void
    {
        [$f, $section, $offering] = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $this->publish($f, $section, $offering);
        $this->policy($f);
        $s->sql->execute('DELETE FROM sis_section_meeting_plans WHERE id = ?', [$section->id->value]);
        $this->rejects(fn() => $a->schedule(), CorruptHistoryException::class);
    }

    public function testIndexMigrationRetryPreservesAllOriginalDefinitionsAndGrants(): void
    {
        [$f] = $this->fixture();
        $s = $f->appointments->sis;
        $before = array_map($s->sql->rows(...), ['sis_history_events', 'sis_section_versions',
            'sis_access_permissions', 'sis_access_implications', 'sis_access_user_roles']);
        $migration = new \AddSisAdvisorCalendarIndex('1');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($s->connection, 'test_migrations')]));
        $s->connection->pdo->exec('PRAGMA query_only=ON');
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), ['sis_history_events', 'sis_section_versions',
            'sis_access_permissions', 'sis_access_implications', 'sis_access_user_roles']));
    }
}
