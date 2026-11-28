<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\ValueObject\ExternalCourseGrade;
use Domain\Lms\Service\MoodleGradeGateway;
use Domain\Lms\Service\MoodleGradeImportPolicy;
use Domain\Lms\Service\MoodleGradeImportService;
use Domain\Lms\Service\MoodleGradeImportWorker;
use Domain\Lms\Service\MoodleGradeSource;
use Domain\Lms\Service\MoodleSectionSyncPolicy;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodlePersonLink;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Lms\SqlMoodleBindingRepository;
use Infrastructure\Lms\SqlMoodleGradeImportRepository;
use Infrastructure\Lms\SqlMoodlePersonLinkRepository;
use Infrastructure\Lms\SqlMoodleSectionRepository;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use SisFixture;

final class MoodleGradeImportFixture
{
    public SisFixture $sis;
    public OfferingCreditFixture $offering;
    public EntityId $student;
    public EntityId $enrollment;
    public MoodleBinding $binding;
    public SqlMoodleGradeImportRepository $records;
    public SqlAccessRepository $roles;
    public MoodleGradeImportPolicy $policy;
    public MoodleGradeGateway $remote;
    public MoodleGradeSource $source;
    public \Domain\Shared\EventSourcing\HistoricalEventStore $history;

    public function __construct()
    {
        require_once __DIR__ . "/SisFixture.php";
        require_once __DIR__ . "/OfferingCreditFixture.php";
        $f = $this->sis = new SisFixture();
        $this->offering = new OfferingCreditFixture($f);
        $this->offering->bind();
        $this->offering->select($this->offering->publish());
        $f->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "B",
                "min_score_milli" => 80000,
                "max_score_milli" => 89999,
                "points_milli" => 3000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $this->student = $f->student()->id;
        $f->service->register($this->student, $this->offering->section->id);
        $this->enrollment = $f->registration->enrollment(
            $this->student,
            $this->offering->section->id,
        )->id;
        $this->roles = new SqlAccessRepository($f->sql);
        $this->roles->assign(
            GrantKind::UserRole,
            $f->access->actor,
            $this->roles->roleNamed("super")->id,
            true,
        );
        $this->policy = new class ($f->access->actor) implements
            MoodleGradeImportPolicy,
            MoodleSectionSyncPolicy
        {
            public bool $on = true;
            public function __construct(private EntityId $actorId)
            {
            }
            public function enabled(): bool
            {
                return $this->on;
            }
            public function actor(): EntityId
            {
                return $this->actorId;
            }
            public function beforeDays(): int
            {
                return 0;
            }
            public function afterDays(): int
            {
                return 0;
            }
        };
        $history = $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new HistoricalSchemaRegistry(),
        );
        $sections = new SqlMoodleSectionRepository(
            $f->sql,
            $f->registration,
            new SqlSectionVersionRepository($f->sql),
            $history,
            $f->admissions,
            $f->enrollmentLedger(),
            $f->activityLedger(),
            $f->transactions,
            $this->policy,
            $f->clock,
        );
        $bindings = new SqlMoodleBindingRepository($f->sql);
        $this->binding = new MoodleBinding(
            EntityId::generate(),
            str_repeat("a", 64),
            str_repeat("b", 64),
            $f->access->actor,
            "2026-08-10T10:00:00Z",
        );
        $bindings->add(
            $this->binding,
            $f->dropCipher->seal("Fictional installation approval."),
        );
        $sections->add(
            EntityId::generate(),
            $this->binding->id,
            $this->offering->section->id,
            "42",
            $f->access->actor,
            "2026-08-10T10:00:00Z",
            $f->dropCipher->seal("Fictional section approval."),
        );
        $links = new SqlMoodlePersonLinkRepository($f->sql);
        $person = $f->admissions->student($this->student)->personId;
        $links->add(
            new MoodlePersonLink(
                EntityId::generate(),
                $this->binding->id,
                $person,
                new MoodleExternalId("73"),
                $f->access->actor,
                "2026-08-10T10:00:00Z",
            ),
            $f->dropCipher->seal("Fictional person approval."),
        );
        $this->records = new SqlMoodleGradeImportRepository($f->sql);
        $this->source = new MoodleGradeSource(
            $bindings,
            $sections,
            $links,
            $f->registration,
            $f->enrollmentLedger(),
            new SqlAcademicCreditRepository($f->sql),
            $f->academicCredits(),
            $f->admissions,
            $f->profileLedger(),
            $f->transactions,
        );
        $this->remote = new class implements MoodleGradeGateway {
            public int $calls = 0;
            public ?\Closure $duringCall = null;
            public bool $fail = false;
            public array $grade = [
                "state" => "available",
                "item_id" => "97",
                "grade" => "91.25000",
                "minimum" => "0.00000",
                "maximum" => "100.00000",
                "revision" => "",
            ];
            public function courseGrade(
                MoodleBinding $binding,
                MoodleExternalId $course,
                MoodleExternalId $user,
            ): ExternalCourseGrade {
                $this->calls++;
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                if ($this->fail) {
                    throw new \RuntimeException("Fictional upstream failure.");
                }
                return new ExternalCourseGrade(
                    array_replace($this->grade, [
                        "revision" => hash("sha256", json_encode($this->grade)),
                    ]),
                );
            }
        };
    }

    public function service(?AuditTrail $audit = null): MoodleGradeImportService
    {
        $f = $this->sis;
        return new MoodleGradeImportService(
            $this->records,
            $this->source,
            $this->policy,
            $f->academicCredits($audit),
            $f->admissions,
            $this->roles,
            $f->access,
            $f->transactions,
            $f->dropCipher,
            $audit ?? $f->audit,
            $f->clock,
            $this->history,
        );
    }

    public function worker(?AuditTrail $audit = null): MoodleGradeImportWorker
    {
        $f = $this->sis;
        return new MoodleGradeImportWorker(
            $this->records,
            $this->source,
            $this->remote,
            $this->policy,
            $this->roles,
            $f->transactions,
            $f->dropCipher,
            $audit ?? $f->audit,
            $f->clock,
        );
    }

    public function collect(): array
    {
        $section = $this->offering->section->id;
        $page = $this->service()->page($section);
        $this->service()->request(
            $section,
            new EntityId($page["request_id"]),
            $page["revision"],
            "Fictional course-total intake.",
        );
        $this->worker()->runOne();
        $this->worker()->runOne();
        return $this->records->page($section)[0];
    }
}
