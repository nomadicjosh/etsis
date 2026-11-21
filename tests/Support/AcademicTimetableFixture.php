<?php

declare(strict_types=1);

namespace Tests\Support;

use CatalogWorkflowFixture;
use DateTimeZone;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Section;
use Domain\Registration\Service\PublishedSectionMeetingPlanReader;
use Domain\Registration\Service\SectionMeetingPlanService;
use Domain\Scheduling\Service\AcademicTimetableService;
use Domain\Scheduling\Service\BookingService;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Student;
use EventStoreFixture;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlAcademicTimetableRepository;
use Infrastructure\Persistence\Repository\SqlBookingCategoryRepository;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use Infrastructure\Persistence\Repository\SqlSectionMeetingPlanRepository;
use SisFixture;

final class AcademicTimetableFixture
{
    public CatalogWorkflowFixture $catalog;
    public SisFixture $sis;
    public HistoricalEventStore $history;
    public SqlSectionMeetingPlanRepository $plans;
    public SectionMeetingPlanService $publications;
    public BookingService $bookings;
    public Student $student;
    public Section $section;

    public function __construct()
    {
        require_once __DIR__ . '/CatalogWorkflowFixture.php';
        $this->catalog = $c = new CatalogWorkflowFixture();
        $this->sis = $f = $c->sis;
        $f->clock->time = $f->clock->time->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $this->student = $f->student($f->access->actor);
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
        $this->plans = new SqlSectionMeetingPlanRepository($f->sql, $f->bookingSections());
        $this->publications = new SectionMeetingPlanService(
            $this->plans,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $c->fixture->cipher,
            $f->clock,
            new PublishedSectionMeetingPlanReader($this->plans, $this->history),
            new \Domain\Registration\Service\FacultyScheduleService(
                new \Infrastructure\Persistence\Repository\SqlFacultyScheduleRepository($f->sql),
                new \Infrastructure\Persistence\Repository\SqlRegistrationScheduleRepository($f->sql),
                $this->history,
                new \Domain\Registration\Service\PublishedSectionMeetingPlanReader($this->plans, $this->history),
            ),
        );
        $this->bookings = new BookingService(
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            new SqlRoomRepository($f->sql),
            $f->clock,
            new SqlBookingCategoryRepository($f->sql),
            $f->bookingSections()
        );
        $this->section = $this->addSection();
        $f->service->register($this->student->id, $this->section->id, true);
    }

    public function addSection(?EntityId $term = null): Section
    {
        $c = $this->catalog;
        $p = $c->plan()->sections[0];
        $section = new Section(
            $p->id,
            $p->courseId,
            $term ?? $p->termId,
            $this->sis->access->actor,
            'MATH.101.' . substr(EntityId::generate()->value, -6),
            30,
            false,
            true,
            new Money(0, 'USD'),
            DeliveryMode::Hybrid
        );
        $plan = new OfferingPlan($section->termId, $section);
        $offerings = $c->offerings();
        $review = $offerings->review($plan);
        $operation = new EntityId($review['operation_id']);
        $offerings->save($operation, $plan, $review['revision']);
        $offerings->publish($operation, $c->publicationReview($offerings, $operation)['review_token']);
        return $section;
    }

    public function publish(?EntityId $section = null): void
    {
        $section ??= $this->section->id;
        $review = $this->publications->review($section, 'Fictional protected publication reason');
        $this->publications->publish($section, $review['review_token']);
    }

    public function service(
        ?HistoricalEventStore $history = null,
        ?\Domain\Shared\Security\StudentAccess $access = null,
    ): AcademicTimetableService {
        return new AcademicTimetableService(
            new SqlAcademicTimetableRepository($this->sis->sql),
            new PublishedSectionMeetingPlanReader($this->plans, $history ?? $this->history),
            $access ?? $this->sis->access,
            $this->sis->clock
        );
    }
}
