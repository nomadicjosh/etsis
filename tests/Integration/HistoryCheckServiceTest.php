<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\AcademicRecord\Repository\AcademicProjectionVerifier;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Person\Repository\PersonProjectionVerifier;
use Domain\Person\ValueObject\PersonProjectionCheck;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Support\Enum\HistoryCheckSubject;
use Domain\Support\Service\HistoryCheckService;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlHistoryCheckRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class HistoryCheckServiceTest extends TestCase
{
    public function testCatalogReceiptsUseNoInventedPersonAndRequireEveryCatalogSupportDuty(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogVerificationFixture.php';
        $f = new \Tests\Support\CatalogVerificationFixture();
        $s = $f->sis;
        $s->access->actor = $s->user();
        $s->access->staff = false;
        $permissions = ['sis:history:verify', 'sis:audit:read', 'sis:catalog:manage'];
        $subjects = [[HistoryCheckSubject::Course, $f->course], [HistoryCheckSubject::Section, $f->section]];
        foreach ($subjects as [$kind, $id]) {
            foreach ($permissions as $missing) {
                $s->access->grants = array_values(array_diff($permissions, [$missing]));
                $this->rejects(fn() => $this->service($s)->run($kind, $id), AccessDeniedException::class);
                $this->rejects(fn() => $this->service($s)->history($kind, $id), AccessDeniedException::class);
            }
            $s->access->grants = $permissions;
            $before = \EventStoreFixture::historyDigest($s->sql);
            $result = $this->service($s)->run($kind, $id);
            self::assertSame('verified', $result['result']['status']);
            self::assertSame([$result['receipt']], $this->service($s)->history($kind, $id)['items']);
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        }
        foreach ($s->sql->rows('sis_history_checks') as $row) {
            self::assertNull($row['person_id']);
            self::assertNull($row['owner_user_id']);
            self::assertNull($row['student_id']);
        }
        self::assertCount(2, $s->sql->rows('sis_history_checks'));
    }

    public function testCatalogFinalAuditFailureAuthorityAndSectionRoutingDriftRollBackReceipts(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogVerificationFixture.php';
        foreach (['audit', 'authority', 'course', 'term'] as $scenario) {
            $f = new \Tests\Support\CatalogVerificationFixture();
            $s = $f->sis;
            $other = $s->section();
            $before = $f->facts();
            $s->access->staff = false;
            $s->access->grants = ['sis:history:verify', 'sis:audit:read', 'sis:catalog:manage'];
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($scenario, $f, $s, $other): void {
                if ($scenario === 'audit') {
                    throw new \RuntimeException('Fictional required audit failure.');
                }
                if ($scenario === 'authority') {
                    $s->access->grants = [];
                }
                if ($scenario === 'course') {
                    $s->sql->update(
                        'sis_sections',
                        ['course_id' => $other->courseId->value],
                        ['id' => $f->section->value]
                    );
                }
                if ($scenario === 'term') {
                    $next = EntityId::generate();
                    $s->sql->insert('sis_terms', [
                        'id' => $next->value, 'code' => 'FICT-NEXT', 'starts_on' => '2027-01-01',
                        'ends_on' => '2027-05-01', 'add_drop_ends_on' => '2027-01-15']);
                    $s->sql->update('sis_sections', ['term_id' => $next->value], ['id' => $f->section->value]);
                }
            });
            $exception = match ($scenario) {
                'authority' => AccessDeniedException::class,
                'audit' => \RuntimeException::class,
                default => ConcurrentModificationException::class,
            };
            $this->rejects(
                fn() => $this->service($s, audit: $audit)->run(HistoryCheckSubject::Section, $f->section),
                $exception
            );
            self::assertSame($before, $f->facts());
        }
    }

    public function testWaitlistChecksRetainOnlyScopedSupportMetadataAndNeverReplayNotices(): void
    {
        require_once dirname(__DIR__) . '/Support/WaitlistVerificationFixture.php';
        $f = new \Tests\Support\WaitlistVerificationFixture();
        $f->close('removed');
        $s = $f->sis;
        $before = $f->facts();
        $service = $this->service($s);
        $result = $service->run(HistoryCheckSubject::Waitlist, $f->entry);
        self::assertSame('verified', $result['result']['status']);
        self::assertSame(1, $result['result']['records_checked']);
        self::assertSame(200, $result['event_limit']);
        self::assertSame([$result['receipt']], $service->history(HistoryCheckSubject::Waitlist, $f->entry)['items']);
        self::assertSame([], $service->history(HistoryCheckSubject::Student, $f->student)['items']);
        self::assertSame('waitlist', $s->sql->rows('sis_history_checks')[0]['subject_type']);
        foreach (['Fictional', 'reason', 'student_id', 'person_id', 'rank', 'enrollment_id'] as $private) {
            self::assertStringNotContainsString($private, json_encode($result));
        }
        self::assertSame($before, $f->facts());
    }

    public function testWaitlistSupportRequiresEverySourceDutyAndProtectsSuperOwners(): void
    {
        require_once dirname(__DIR__) . '/Support/WaitlistVerificationFixture.php';
        $f = new \Tests\Support\WaitlistVerificationFixture();
        $s = $f->sis;
        $s->access->staff = false;
        $duties = ['sis:history:verify', 'sis:audit:read', ...HistoryCheckSubject::Waitlist->permissions()];
        $service = $this->service($s);
        foreach ($duties as $missing) {
            $s->access->grants = array_values(array_diff($duties, [$missing]));
            $this->rejects(
                fn () => $service->run(HistoryCheckSubject::Waitlist, $f->entry),
                AccessDeniedException::class,
            );
            $this->rejects(
                fn () => $service->history(HistoryCheckSubject::Waitlist, $f->entry),
                AccessDeniedException::class,
            );
        }
        $s->access->grants = $duties;
        $owner = $s->admissions->person($s->admissions->student($f->student)->personId)->ownerUserId;
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $roles->roleNamed('super')->id, true);
        $this->rejects(
            fn () => $service->run(HistoryCheckSubject::Waitlist, $f->entry),
            AccessDeniedException::class,
        );
        $this->rejects(
            fn () => $service->history(HistoryCheckSubject::Waitlist, $f->entry),
            AccessDeniedException::class,
        );
        self::assertSame([], $s->sql->rows('sis_history_checks'));
    }

    public function testWaitlistReceiptAndAuditRollBackWhenAuthorityOrRoutingChanges(): void
    {
        require_once dirname(__DIR__) . '/Support/WaitlistVerificationFixture.php';
        foreach (['audit', 'authority', 'section', 'person'] as $failure) {
            $f = new \Tests\Support\WaitlistVerificationFixture();
            $s = $f->sis;
            $otherSection = $s->section()->id;
            $otherPerson = $s->legacyPerson($s->user(), 'Other', 'Fictional')->id;
            $before = $f->facts();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use (
                $failure,
                $f,
                $s,
                $otherSection,
                $otherPerson,
            ): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('PRIVATE unavailable required audit');
                }
                if ($failure === 'authority') {
                    $s->access->staff = false;
                } else {
                    $s->sql->update(
                        'sis_waitlist_entries',
                        $failure === 'section'
                            ? ['section_id' => $otherSection->value] : ['person_id' => $otherPerson->value],
                        ['id' => $f->entry->value]
                    );
                }
            });
            $expected = match ($failure) {
                'audit' => \RuntimeException::class,
                'section' => ConcurrentModificationException::class,
                default => AccessDeniedException::class,
            };
            $this->rejects(
                fn () => $this->service($s, audit: $audit)->run(HistoryCheckSubject::Waitlist, $f->entry),
                $expected,
            );
            self::assertSame([], $s->sql->rows('sis_history_checks'));
            self::assertSame($before, $f->facts());
        }
    }

    public function testSelectedCreditChecksKeepAcademicFactsPrivateAndUseExistingReceipts(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        $s = new \SisFixture();
        $t = new \TransferAwardFixture($s, $s->dropCipher);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $credits = $s->sql->rows('sis_academic_credits');
        $service = $this->service($s);
        $result = $service->run(HistoryCheckSubject::Credit, $t->credit);
        self::assertSame('verified', $result['result']['status']);
        self::assertSame(1, $result['result']['records_checked']);
        self::assertSame(200, $result['event_limit']);
        self::assertSame([$result['receipt']], $service->history(HistoryCheckSubject::Credit, $t->credit)['items']);
        self::assertSame([], $service->history(HistoryCheckSubject::Student, $t->student)['items']);
        $receipt = $s->sql->rows('sis_history_checks')[0];
        self::assertSame('credit', $receipt['subject_type']);
        foreach (['grade', 'course_code', 'source_id', 'payload', 'reason'] as $private) {
            self::assertStringNotContainsString($private, json_encode($result));
        }
        self::assertSame($credits, $s->sql->rows('sis_academic_credits'));
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testSelectedCreditSupportRequiresEveryDutyAndProtectsSuperOwners(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        $s = new \SisFixture();
        $t = new \TransferAwardFixture($s, $s->dropCipher, $s->student($s->user())->id);
        $s->access->staff = false;
        $service = $this->service($s);
        $duties = ['sis:student:read', 'sis:audit:read', 'sis:history:verify'];
        foreach ($duties as $missing) {
            $s->access->grants = array_values(array_diff($duties, [$missing]));
            $this->rejects(
                fn () => $service->run(HistoryCheckSubject::Credit, $t->credit),
                AccessDeniedException::class
            );
            $this->rejects(
                fn () => $service->history(HistoryCheckSubject::Credit, $t->credit),
                AccessDeniedException::class
            );
        }
        $s->access->grants = $duties;
        $owner = $s->admissions->person($s->admissions->student($t->student)->personId)->ownerUserId;
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $roles->roleNamed('super')->id, true);
        $this->rejects(
            fn () => $service->run(HistoryCheckSubject::Credit, $t->credit),
            AccessDeniedException::class
        );
        self::assertSame([], $s->sql->rows('sis_history_checks'));
    }

    public function testSelectedCreditVerificationRechecksRoutingAndRollsBackFailedReceiptAudits(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        foreach (['routing', 'audit', 'permission'] as $kind) {
            $s = new \SisFixture();
            $t = new \TransferAwardFixture($s, $s->dropCipher, $s->student($s->user())->id);
            $other = $s->legacyPerson($s->user(), 'Other', 'Fictional');
            $provider = $this->createMock(AcademicProjectionVerifier::class);
            $provider->expects(self::once())->method('credit')->with($t->credit, 200)
                ->willReturnCallback(function () use ($s, $t, $other, $kind) {
                    self::assertFalse($s->sql->inTransaction());
                    if ($kind === 'routing') {
                        $s->sql->update(
                            'sis_students',
                            ['person_id' => $other->id->value],
                            ['id' => $t->student->value]
                        );
                    }
                    return new \Domain\AcademicRecord\ValueObject\AcademicRecordProjectionCheck(
                        'verified',
                        $t->credit,
                        1,
                        1,
                        false
                    );
                });
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $kind): void {
                if ($kind === 'audit') {
                    throw new \RuntimeException('Fictional failed required audit.');
                }
                $s->access->staff = false;
                $s->access->grants = [];
            });
            $expected = match ($kind) {
                'routing' => ConcurrentModificationException::class,
                'permission' => AccessDeniedException::class,
                default => \RuntimeException::class,
            };
            $before = \EventStoreFixture::historyDigest($s->sql);
            $this->rejects(fn () => $this->service($s, audit: $audit, academics: $provider)
                ->run(HistoryCheckSubject::Credit, $t->credit), $expected);
            self::assertSame([], $s->sql->rows('sis_history_checks'));
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        }
    }

    public function testProgressSupportProtectsSuperOwnersAndRetainsOnlyMinimizedMetadata(): void
    {
        require_once dirname(__DIR__) . '/Support/RecordedProgressVerificationFixture.php';
        $f = new \Tests\Support\RecordedProgressVerificationFixture();
        $s = $f->sis;
        $owner = $s->admissions->person($s->admissions->student($f->curriculum->student)->personId)->ownerUserId;
        $roles = new SqlAccessRepository($s->sql);
        $kind = \Domain\Access\Enum\GrantKind::UserRole;
        $roles->assign($kind, $owner, $roles->roleNamed('super')->id, true);
        $service = $this->service($s);
        $this->rejects(fn () => $service->run(HistoryCheckSubject::Progress, $f->record), AccessDeniedException::class);
        self::assertSame([], $s->sql->rows('sis_history_checks'));
        $roles->assign($kind, $owner, $roles->roleNamed('super')->id, false);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $projection = $s->sql->rows('sis_degree_progress_records');
        $result = $service->run(HistoryCheckSubject::Progress, $f->record);
        self::assertSame('verified', $result['result']['status']);
        self::assertSame([$result['receipt']], $service->history(HistoryCheckSubject::Progress, $f->record)['items']);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        self::assertSame($projection, $s->sql->rows('sis_degree_progress_records'));
        foreach (['Fictional', 'inputs', 'snapshot', 'source_references', 'source_hash', 'reason'] as $private) {
            self::assertStringNotContainsString($private, json_encode($result, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString(
                $private,
                json_encode($s->sql->rows('sis_history_checks'), JSON_THROW_ON_ERROR)
            );
        }
    }

    public function testProgressSupportRollsBackReceiptAuditAndRoutingFailures(): void
    {
        require_once dirname(__DIR__) . '/Support/RecordedProgressVerificationFixture.php';
        $f = new \Tests\Support\RecordedProgressVerificationFixture();
        $s = $f->sis;
        foreach (['audit', 'authority', 'routing'] as $failure) {
            $s->access->staff = true;
            $before = $f->facts();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($failure, $s, $f): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('PRIVATE unavailable audit');
                }
                if ($failure === 'authority') {
                    $s->access->staff = false;
                } else {
                    $s->sql->update(
                        'sis_degree_progress_records',
                        ['student_id' => EntityId::generate()->value],
                        ['id' => $f->record->value]
                    );
                }
            });
            $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
            $this->rejects(
                fn () => $this->service($s, audit: $audit)->run(HistoryCheckSubject::Progress, $f->record),
                $failure === 'audit' ? \RuntimeException::class : AccessDeniedException::class
            );
            self::assertSame([], $s->sql->rows('sis_history_checks'));
            self::assertSame($before, $f->facts());
        }
    }

    public function testCurriculumChecksRequireEveryDutyAndKeepBusinessHistoryUnchanged(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentDecisionFixture.php';
        $c = new CampusFixture();
        $f = new \Tests\Support\StudentDecisionFixture($c->sis);
        $s = $c->sis;
        $s->access->actor = $s->user();
        $s->access->staff = false;
        foreach ([HistoryCheckSubject::Advisors, HistoryCheckSubject::Requirements] as $kind) {
            $grants = ['sis:history:verify', 'sis:audit:read', ...$kind->permissions()];
            $s->access->grants = $grants;
            $before = \EventStoreFixture::historyDigest($s->sql);
            $result = $this->service($c)->run($kind, $f->pursuit);
            self::assertSame('observed_empty', $result['result']['status']);
            self::assertSame($f->pursuit->value, $result['result']['pursuit_reference']);
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
            $count = count($s->sql->rows('sis_history_checks'));
            foreach ($grants as $missing) {
                $s->access->grants = array_values(array_diff($grants, [$missing]));
                $this->rejects(fn() => $this->service($c)->run($kind, $f->pursuit), AccessDeniedException::class);
                $this->rejects(fn() => $this->service($c)->history($kind, $f->pursuit), AccessDeniedException::class);
                self::assertCount($count, $s->sql->rows('sis_history_checks'));
            }
        }
    }

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CampusFixture.php";
    }

    private function service(
        CampusFixture|\SisFixture $c,
        ?PersonProjectionVerifier $people = null,
        ?AuditTrail $audit = null,
        ?AcademicProjectionVerifier $academics = null,
        ?\Domain\Admissions\Repository\ApplicationProjectionVerifier $applications = null,
        ?\Domain\Student\Repository\StudentDecisionProjectionVerifier $decisions = null,
        ?\Domain\HumanResources\Repository\EmploymentProjectionVerifier $employment = null,
        ?\Domain\Catalog\Repository\CatalogProjectionVerifier $catalog = null,
    ): HistoryCheckService {
        $s = $c instanceof CampusFixture ? $c->sis : $c;
        $cipher = $c instanceof CampusFixture ? $c->cipher : $s->dropCipher;
        return new HistoryCheckService(
            new SqlHistoryCheckRepository($s->sql),
            $people ??
                new \Infrastructure\Projection\SqlPersonProjectionVerifier(
                    $s->sql,
                    \EventStoreFixture::history($s->connection->queryBuilder()),
                    new \Infrastructure\Persistence\Repository\SqlPersonContactRepository(
                        $s->sql,
                        $cipher,
                    ),
                    new \Infrastructure\Persistence\Repository\SqlAddressBookRepository(
                        $s->sql,
                        $cipher,
                    ),
                    new \Infrastructure\Persistence\Repository\SqlEmergencyContactsRepository(
                        $s->sql,
                        $cipher,
                    ),
                    new \Infrastructure\Persistence\Repository\SqlEducationBackgroundRepository(
                        $s->sql,
                        $cipher,
                    ),
                ),
            $academics ??
                new \Infrastructure\Projection\SqlAcademicProjectionVerifier(
                    $s->sql,
                    new \Infrastructure\Persistence\Repository\SqlAcademicInputRepository($s->sql),
                    $s->inputVerifier(),
                ),
            new \Infrastructure\Projection\SqlAccountProjectionVerifier(
                $s->sql,
                \EventStoreFixture::history($s->connection->queryBuilder()),
            ),
            $applications ?? new \Infrastructure\Projection\SqlApplicationProjectionVerifier(
                $s->sql,
                $s->admissions,
                $s->decisionLedger(),
            ),
            $decisions ?? $s->studentDecisionVerifier(),
            new \Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository($s->sql),
            $s->admissions,
            new SqlPersonProfileRepository($s->sql),
            new SqlStudentRecordRepository($s->sql),
            new SqlAccessRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            $employment ?? $s->employmentVerifier(),
            new \Infrastructure\Persistence\Repository\SqlTimeRepository($s->sql, $s->dropCipher),
            new \Infrastructure\Persistence\Repository\SqlDegreeProgressRecordRepository($s->sql),
            new \Infrastructure\Projection\SqlWaitlistProjectionVerifier(
                $s->sql,
                new \Infrastructure\Persistence\Waitlist\SqlWaitlistRepository($s->sql, $s->dropCipher),
                $s->waitlistLedger(),
            ),
            new \Infrastructure\Persistence\Waitlist\SqlWaitlistRepository($s->sql, $s->dropCipher),
            $catalog ?? new \Infrastructure\Projection\SqlCatalogProjectionVerifier(
                $s->sql,
                \EventStoreFixture::history($s->connection->queryBuilder()),
                new \Infrastructure\Persistence\Repository\SqlCourseVersionProjection($s->sql),
                new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($s->sql),
                new \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository($s->sql)
            ),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
        );
    }

    private function fixture(): array
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $owner = $s->user();
        $student = $s->student($owner);
        $s->sql->update(
            "users",
            ["first_name" => "Alex", "last_name" => "Example"],
            ["user_id" => $owner->value],
        );
        $a = StudentAccount::open(new AccountId(), $student->id, $s->term, "USD");
        $a->post(
            EntityId::generate(),
            EntryType::Charge,
            new Money(10000, "USD"),
            $s->access->actor,
            staffRecorded: true,
        );
        $s->saveAccount($a);
        $application = $s->submissionService()->apply(EntityId::generate(), $student->personId, $s->program, $s->term);
        return [$c, $student, new EntityId((string) $a->aggregateId()), $application];
    }

    private function facts(CampusFixture $c): array
    {
        return array_map($c->sis->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_persons",
            "sis_applications",
            "sis_application_intake",
            "sis_students",
            "sis_academic_credits",
            "sis_enrollments",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
            "sis_campaigns",
            "sis_deliveries",
        ]);
    }

    private function rejects(callable $run, string $type): void
    {
        try {
            $run();
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
            return;
        }
        self::fail("A forbidden support operation was accepted.");
    }

    public function testStandingAndLoadUseIndependentDutiesAndRetainOnlySupportMetadata(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentDecisionFixture.php';
        $c = new CampusFixture();
        $f = new \Tests\Support\StudentDecisionFixture($c->sis);
        $s = $c->sis;
        $service = $this->service($c);
        $cases = [[HistoryCheckSubject::Standing, $f->pursuit], [HistoryCheckSubject::Load, $f->determination]];
        foreach ($cases as [$kind, $id]) {
            $before = $this->facts($c);
            $result = $service->run($kind, $id);
            self::assertSame('verified', $result['result']['status']);
            self::assertSame(1, $result['result']['records_checked']);
            self::assertSame([$result['receipt']], $service->history($kind, $id)['items']);
            self::assertSame($before, $this->facts($c));
            self::assertStringNotContainsString('Private', json_encode($result));
            self::assertStringNotContainsString('credits_milli', json_encode($result));
            $s->access->staff = false;
            $all = ['sis:history:verify', 'sis:audit:read', ...$kind->permissions()];
            foreach ($all as $missing) {
                $s->access->grants = array_values(array_diff($all, [$missing]));
                $this->rejects(fn () => $service->run($kind, $id), AccessDeniedException::class);
                $this->rejects(fn () => $service->history($kind, $id), AccessDeniedException::class);
            }
            $s->access->staff = true;
            $s->access->grants = [];
        }
        self::assertCount(2, $s->sql->rows('sis_history_checks'));
    }

    public function testDecisionSupportProtectsSuperOwnersAndCurrentPursuitRouting(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentDecisionFixture.php';
        $c = new CampusFixture();
        $f = new \Tests\Support\StudentDecisionFixture($c->sis);
        $s = $c->sis;
        $owner = $s->admissions->person($s->admissions->student($f->student)->personId)->ownerUserId;
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $roles->roleNamed('super')->id, true);
        $service = $this->service($c);
        $cases = [[HistoryCheckSubject::Standing, $f->pursuit], [HistoryCheckSubject::Load, $f->determination]];
        foreach ($cases as [$kind, $id]) {
            $this->rejects(fn () => $service->run($kind, $id), AccessDeniedException::class);
        }
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $owner, $roles->roleNamed('super')->id, false);
        $provider = $this->createStub(\Domain\Student\Repository\StudentDecisionProjectionVerifier::class);
        $provider->method('load')->willReturnCallback(function () use ($s, $f) {
            $s->sql->update(
                'sis_load_determinations',
                ['pursuit_id' => EntityId::generate()->value],
                ['id' => $f->determination->value],
            );
            return new \Domain\Student\ValueObject\StudentDecisionProjectionCheck(
                'verified',
                'enrollment_load_determination',
                $f->determination,
                1,
                1,
                1,
            );
        });
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->rejects(
            fn () => $this->service($c, decisions: $provider)->run(HistoryCheckSubject::Load, $f->determination),
            ConcurrentModificationException::class
        );
        self::assertSame([], $s->sql->rows('sis_history_checks'));
    }

    public function testDecisionSupportFinalAuditOrAuthorityFailureRollsBackReceipts(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentDecisionFixture.php';
        $c = new CampusFixture();
        $f = new \Tests\Support\StudentDecisionFixture($c->sis);
        $s = $c->sis;
        foreach (['audit', 'authority'] as $failure) {
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($s, $failure): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional unavailable audit');
                }
                $s->access->staff = false;
            });
            $cases = [[HistoryCheckSubject::Standing, $f->pursuit], [HistoryCheckSubject::Load, $f->determination],
                [HistoryCheckSubject::Advisors, $f->pursuit], [HistoryCheckSubject::Requirements, $f->pursuit]];
            foreach ($cases as [$kind, $id]) {
                $s->access->staff = true;
                $this->rejects(
                    fn () => $this->service($c, audit: $audit)->run($kind, $id),
                    $failure === 'audit' ? \RuntimeException::class : AccessDeniedException::class
                );
                self::assertSame([], $s->sql->rows('sis_history_checks'));
            }
        }
    }

    public function testApplicationRoutingChangeSuppressesTheResultAndSupportReceipt(): void
    {
        [$c, , , $application] = $this->fixture();
        $s = $c->sis;
        $other = $s->legacyPerson($s->user(), 'Other', 'Fictional person');
        $provider = $this->createStub(\Domain\Admissions\Repository\ApplicationProjectionVerifier::class);
        $provider->method('verify')->willReturnCallback(function () use ($s, $application, $other) {
            $s->sql->update('sis_applications', ['person_id' => $other->id->value], ['id' => $application->id->value]);
            return new \Domain\Admissions\ValueObject\ApplicationProjectionCheck(
                'verified',
                $application->id,
                1,
                1,
                0,
                earlierHistoryUnknown: false,
            );
        });
        $this->rejects(
            fn () => $this->service($c, applications: $provider)->run(
                HistoryCheckSubject::Application,
                $application->id,
            ),
            ConcurrentModificationException::class,
        );
        self::assertSame([], $s->sql->rows('sis_history_checks'));
    }

    public function testApplicationSupportReceiptAndAuditRollBackTogether(): void
    {
        [$c, , , $application] = $this->fixture();
        $before = $this->facts($c);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(
            new \RuntimeException('Audit unavailable.'),
        );
        $this->rejects(
            fn () => $this->service($c, audit: $audit)->run(HistoryCheckSubject::Application, $application->id),
            \RuntimeException::class,
        );
        self::assertSame([], $c->sis->sql->rows('sis_history_checks'));
        self::assertSame($before, $this->facts($c));
    }

    public function testRealProfileAcademicAndFinancialChecksRetainOnlyMinimalReceiptsWithoutChangingFacts(): void
    {
        [$c, $student, $account, $application] = $this->fixture();
        $s = $c->sis;
        $service = $this->service($c);
        $before = $this->facts($c);
        $cases = [
            [HistoryCheckSubject::Person, $student->personId, "history_gaps"],
            [HistoryCheckSubject::Student, $student->id, "observed_empty"],
            [HistoryCheckSubject::Account, $account, "verified"],
            [HistoryCheckSubject::Application, $application->id, "verified"],
        ];
        foreach ($cases as [$kind, $id, $status]) {
            $result = $service->run($kind, $id);
            self::assertSame($status, $result["result"]["status"]);
            self::assertSame(200, $result["event_limit"]);
            self::assertFalse($result["result"]["records_changed"]);
            self::assertSame("2026-08-10T17:00:00Z", $result["receipt"]["checked_at"]);
            self::assertSame([$result["receipt"]], $service->history($kind, $id)["items"]);
            self::assertSame(
                "support.history_checked",
                $s->audit->forSubject(new EntityId($result["receipt"]["reference"]))[0]["action"],
            );
            foreach (
                [
                    "amount",
                    "balance_minor",
                    "given_name",
                    "email",
                    "reason",
                    "payload",
                    "sealed",
                    "password",
                ] as $private
            ) {
                self::assertStringNotContainsString($private, json_encode($result));
            }
        }
        self::assertCount(4, $s->sql->rows("sis_history_checks"));
        foreach ($s->sql->rows("sis_history_checks") as $row) {
            $keys = array_keys($row);
            sort($keys);
            self::assertSame(
                [
                    "actor_id",
                    "checked_at",
                    "events_checked",
                    "id",
                    "owner_user_id",
                    "person_id",
                    "status",
                    "student_id",
                    "subject_id",
                    "subject_type",
                ],
                $keys,
            );
        }
        self::assertSame($before, $this->facts($c));
        self::assertFalse($s->connection->pdo->inTransaction());
    }

    public function testEmploymentChecksUseExistingReceiptsAndRequireAllDutiesEvenForOwners(): void
    {
        require_once dirname(__DIR__) . '/Support/EmploymentHistoryFixture.php';
        $c = new CampusFixture();
        $f = new \Tests\Support\EmploymentHistoryFixture($c->sis);
        $s = $c->sis;
        $service = $this->service($c);
        $cases = [[HistoryCheckSubject::PrimaryPosition, $f->employee],
            [HistoryCheckSubject::Position, $f->position], [HistoryCheckSubject::TimeEntry, $f->entry]];
        $before = $f->facts();
        foreach ($cases as [$kind, $id]) {
            self::assertLessThanOrEqual(8, strlen($kind->value));
            $checked = $service->run($kind, $id);
            self::assertSame('verified', $checked['result']['status']);
            self::assertSame('verified', $checked['receipt']['status']);
            self::assertSame(200, $checked['event_limit']);
            self::assertCount(1, $service->history($kind, $id)['items']);
            $json = json_encode($checked, JSON_THROW_ON_ERROR);
            foreach (['Private', 'Fictional', 'encrypted_', 'starts_at', '4321', 'owner_user_id'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
            $s->access->staff = false;
            $s->access->actor = $f->owner;
            $all = ['sis:hr:manage', 'sis:history:verify', 'sis:audit:read'];
            foreach ($all as $missing) {
                $s->access->grants = array_values(array_diff($all, [$missing]));
                $this->rejects(fn() => $service->run($kind, $id), AccessDeniedException::class);
                $this->rejects(fn() => $service->history($kind, $id), AccessDeniedException::class);
            }
            $s->access->staff = true;
        }
        self::assertSame($before, $f->facts());
    }

    public function testEmploymentChecksProtectSuperOwnersAndRollBackRoutingAuditAndAuthorityFailures(): void
    {
        require_once dirname(__DIR__) . '/Support/EmploymentHistoryFixture.php';
        foreach (['super', 'routing', 'audit', 'authority'] as $case) {
            $c = new CampusFixture();
            $f = new \Tests\Support\EmploymentHistoryFixture($c->sis);
            $s = $c->sis;
            if ($case === 'super') {
                $roles = new SqlAccessRepository($s->sql);
                $superRole = $roles->roleNamed('super')->id;
                $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $f->owner, $superRole, true);
                $this->rejects(
                    fn() => $this->service($c)->run(HistoryCheckSubject::Position, $f->position),
                    AccessDeniedException::class,
                );
                self::assertSame([], $s->sql->rows('sis_history_checks'));
                continue;
            }
            $other = $s->student($s->user());
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $f, $other, $case): void {
                if ($case === 'routing') {
                    $s->sql->update(
                        'sis_employees',
                        ['person_id' => $other->personId->value],
                        ['id' => $f->employee->value],
                    );
                } elseif ($case === 'authority') {
                    $s->access->staff = false;
                    $s->access->grants = [];
                } else {
                    throw new \RuntimeException('Private failed required audit.');
                }
            });
            $before = $f->facts();
            $this->rejects(
                fn() => $this->service($c, audit: $audit)->run(HistoryCheckSubject::TimeEntry, $f->entry),
                $case === 'routing' ? ConcurrentModificationException::class
                    : ($case === 'authority' ? AccessDeniedException::class : \RuntimeException::class),
            );
            self::assertSame([], $s->sql->rows('sis_history_checks'));
            self::assertSame($before, $f->facts());
        }
    }

    public function testEmploymentProviderAllowanceAndResultRoutingAreCheckedBeforeAReceipt(): void
    {
        require_once dirname(__DIR__) . '/Support/EmploymentHistoryFixture.php';
        $c = new CampusFixture();
        $f = new \Tests\Support\EmploymentHistoryFixture($c->sis);
        $provider = $this->createMock(\Domain\HumanResources\Repository\EmploymentProjectionVerifier::class);
        $provider->expects(self::once())->method('primaryPosition')->with($f->employee, 200)->willReturn(
            new \Domain\HumanResources\ValueObject\EmploymentProjectionCheck(
                'limited',
                'employment_primary_position',
                $f->employee,
                200,
                1,
            ),
        );
        $result = $this->service($c, employment: $provider)->run(HistoryCheckSubject::PrimaryPosition, $f->employee);
        self::assertSame('limited', $result['receipt']['status']);
        self::assertSame(200, $result['receipt']['events_checked']);
        $wrong = $this->createStub(\Domain\HumanResources\Repository\EmploymentProjectionVerifier::class);
        $wrong->method('position')->willReturn(new \Domain\HumanResources\ValueObject\EmploymentProjectionCheck(
            'verified',
            'employment_position',
            EntityId::generate(),
            1,
            1,
            1,
        ));
        $result = $this->service($c, employment: $wrong)->run(HistoryCheckSubject::Position, $f->position);
        self::assertSame('unverified', $result['receipt']['status']);
        self::assertSame(['unavailable'], $result['result']['issues']);
    }

    public function testEveryContextNeedsAllIndependentDutiesEvenForTheOwner(): void
    {
        [$c, $student, $account, $application] = $this->fixture();
        $s = $c->sis;
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $s->access->staff = false;
        $service = $this->service($c);
        foreach (
            [
                [HistoryCheckSubject::Person, $student->personId],
                [HistoryCheckSubject::Student, $student->id],
                [HistoryCheckSubject::Account, $account],
                [HistoryCheckSubject::Application, $application->id],
            ] as [$kind, $id]
        ) {
            $all = ["sis:history:verify", "sis:audit:read", ...$kind->permissions()];
            foreach ($all as $missing) {
                $s->access->grants = array_values(array_diff($all, [$missing]));
                $this->rejects(fn() => $service->run($kind, $id), AccessDeniedException::class);
                $this->rejects(fn() => $service->history($kind, $id), AccessDeniedException::class);
            }
            $s->access->grants = $all;
            self::assertSame([], $service->history($kind, $id)["items"]);
        }
        self::assertSame([], $s->sql->rows("sis_history_checks"));
    }

    public function testSuperProtectedSourcesRequireSuperSupportAuthority(): void
    {
        [$c, $student, $account, $application] = $this->fixture();
        $s = $c->sis;
        $roles = new SqlAccessRepository($s->sql);
        $owner = $s->admissions->person($student->personId)->ownerUserId;
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $owner,
            $roles->roleNamed("super")->id,
            true,
        );
        $service = $this->service($c);
        foreach (
            [
                [HistoryCheckSubject::Person, $student->personId],
                [HistoryCheckSubject::Student, $student->id],
                [HistoryCheckSubject::Account, $account],
                [HistoryCheckSubject::Application, $application->id],
            ] as [$kind, $id]
        ) {
            $this->rejects(fn() => $service->run($kind, $id), AccessDeniedException::class);
        }
        $s->access->actor = $owner;
        self::assertSame(
            "verified",
            $service->run(HistoryCheckSubject::Account, $account)["result"]["status"],
        );
    }

    public function testSourceFailuresWrongReferencesAndExcessiveProvidersNeverExposeExceptionsOrSaveFacts(): void
    {
        [$c, $student] = $this->fixture();
        $people = $this->createStub(PersonProjectionVerifier::class);
        $people
            ->method("verify")
            ->willThrowException(
                new \RuntimeException("PRIVATE fake credential and profile payload"),
            );
        $before = $this->facts($c);
        $result = $this->service($c, $people)->run(HistoryCheckSubject::Person, $student->personId);
        self::assertSame(["unavailable"], $result["result"]["issues"]);
        self::assertSame("unverified", $result["receipt"]["status"]);
        self::assertStringNotContainsString("PRIVATE", json_encode($result));
        foreach (
            [
                new PersonProjectionCheck(
                    "unverified",
                    EntityId::generate(),
                    0,
                    issues: ["unavailable"],
                ),
                new PersonProjectionCheck("limited", $student->personId, 201),
            ] as $unexpected
        ) {
            $people = $this->createStub(PersonProjectionVerifier::class);
            $people->method("verify")->willReturn($unexpected);
            self::assertSame(
                0,
                $this->service($c, $people)->run(HistoryCheckSubject::Person, $student->personId)[
                    "result"
                ]["events_checked"],
            );
        }
        self::assertSame($before, $this->facts($c));
    }

    public function testVerificationIsOutsideReceiptTransactionsAndUsesTheFixedBudget(): void
    {
        [$c, $student] = $this->fixture();
        $people = $this->createMock(PersonProjectionVerifier::class);
        $people
            ->expects(self::once())
            ->method("verify")
            ->with($student->personId, 200)
            ->willReturnCallback(static function () use ($c, $student): PersonProjectionCheck {
                self::assertFalse($c->sis->connection->pdo->inTransaction());
                return new PersonProjectionCheck("limited", $student->personId, 200);
            });
        $result = $this->service($c, $people)->run(HistoryCheckSubject::Person, $student->personId);
        self::assertSame("limited", $result["receipt"]["status"]);
        self::assertSame(200, $result["receipt"]["events_checked"]);
    }

    public function testAuditFailureAndFinalPermissionRevocationRollBackReceipts(): void
    {
        [$c, $student] = $this->fixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(new \RuntimeException("Fictional failed required audit."));
        $this->rejects(
            fn() => $this->service($c, audit: $audit)->run(
                HistoryCheckSubject::Person,
                $student->personId,
            ),
            \RuntimeException::class,
        );
        self::assertSame([], $c->sis->sql->rows("sis_history_checks"));
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($c): void {
            $c->sis->access->staff = false;
            $c->sis->access->grants = [];
        });
        $this->rejects(
            fn() => $this->service($c, audit: $audit)->run(
                HistoryCheckSubject::Person,
                $student->personId,
            ),
            AccessDeniedException::class,
        );
        self::assertSame([], $c->sis->sql->rows("sis_history_checks"));
    }

    public function testRoutingChangeDuringVerificationIsRejectedAndEarlierRoutingReceiptsStayRestricted(): void
    {
        [$c, $student] = $this->fixture();
        $s = $c->sis;
        $service = $this->service($c);
        $service->run(HistoryCheckSubject::Person, $student->personId);
        $people = $this->createStub(PersonProjectionVerifier::class);
        $people
            ->method("verify")
            ->willReturnCallback(static function () use ($s, $student): PersonProjectionCheck {
                $s->sql->update(
                    "sis_persons",
                    ["owner_user_id" => $s->user()->value],
                    ["id" => $student->personId->value],
                );
                return new PersonProjectionCheck(
                    "unverified",
                    $student->personId,
                    0,
                    issues: ["account_link"],
                );
            });
        $this->rejects(
            fn() => $this->service($c, $people)->run(
                HistoryCheckSubject::Person,
                $student->personId,
            ),
            ConcurrentModificationException::class,
        );
        self::assertCount(1, $s->sql->rows("sis_history_checks"));
        self::assertSame(
            [],
            $service->history(HistoryCheckSubject::Person, $student->personId)["items"],
        );
    }

    public function testReceiptPagesAreBoundedAndSeparateContextsEvenForTheSamePerson(): void
    {
        [$c, $student, $account] = $this->fixture();
        $service = $this->service($c);
        for ($i = 0; $i < 12; $i++) {
            $service->run(HistoryCheckSubject::Person, $student->personId);
        }
        $service->run(HistoryCheckSubject::Student, $student->id);
        $service->run(HistoryCheckSubject::Account, $account);
        $first = $service->history(HistoryCheckSubject::Person, $student->personId);
        $last = $service->history(
            HistoryCheckSubject::Person,
            $student->personId,
            new EntityId($first["next_after"]),
        );
        self::assertCount(10, $first["items"]);
        self::assertCount(2, $last["items"]);
        self::assertNull($last["next_after"]);
        self::assertCount(
            12,
            array_unique(array_column([...$first["items"], ...$last["items"]], "reference")),
        );
        self::assertCount(
            1,
            $service->history(HistoryCheckSubject::Student, $student->id)["items"],
        );
    }
    public function testUnlinkedEarlierPeopleCanBeCheckedWithoutCreatingAccountsAndDamagedReceiptsFailClosed(): void
    {
        [$c, $student] = $this->fixture();
        $s = $c->sis;
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => null],
            ["id" => $student->personId->value],
        );
        $before = $this->facts($c);
        $service = $this->service($c);
        $result = $service->run(HistoryCheckSubject::Person, $student->personId);
        self::assertSame("unverified", $result["result"]["status"]);
        self::assertContains("account_link", $result["result"]["issues"]);
        self::assertCount(
            1,
            $service->history(HistoryCheckSubject::Person, $student->personId)["items"],
        );
        self::assertSame($before, $this->facts($c));
        $s->sql->update(
            "sis_history_checks",
            ["checked_at" => "invalid"],
            ["id" => $result["receipt"]["reference"]],
        );
        $this->rejects(
            fn() => $service->history(HistoryCheckSubject::Person, $student->personId),
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
    }

    public function testAccountChangesAndActorSwitchesAfterRequiredAuditCannotPublishAReceipt(): void
    {
        [$c, $student] = $this->fixture();
        $s = $c->sis;
        foreach (["owner", "actor"] as $change) {
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($s, $student, $change): void {
                    if ($change === "owner") {
                        $s->sql->update(
                            "sis_persons",
                            ["owner_user_id" => $s->user()->value],
                            ["id" => $student->personId->value],
                        );
                    } else {
                        $s->access->actor = $s->user();
                    }
                });
            $this->rejects(
                fn() => $this->service($c, audit: $audit)->run(
                    HistoryCheckSubject::Person,
                    $student->personId,
                ),
                $change === "owner"
                    ? ConcurrentModificationException::class
                    : AccessDeniedException::class,
            );
            self::assertSame([], $s->sql->rows("sis_history_checks"));
        }
    }

    public function testProtectedPermissionAndReceiptStorageCompileForAllFiveDatabasesWithoutAnotherJournal(): void
    {
        [$c, $student] = $this->fixture();
        $s = $c->sis;
        $this->service($c)->run(HistoryCheckSubject::Person, $student->personId);
        $before = array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_history_checks",
            "sis_audit",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ]);
        // Exercise the actual upgrade path, where the newly seeded permission does not exist yet.
        $seed = $s->sql->rows('sis_access_permissions', ['name' => 'sis:history:verify'], 1)[0];
        $s->sql->execute(
            'DELETE FROM sis_access_implications WHERE ancestor_id = ? OR descendant_id = ?',
            [$seed['id'], $seed['id']]
        );
        $s->sql->execute('DELETE FROM sis_access_permissions WHERE id = ?', [$seed['id']]);
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (
                string $name,
                callable $build
            ) use (
                &$definitions,
            ): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $definitions[$name] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $migration = new \CreateSisHistoryChecks("support-compiler");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "fixture",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame(["sis_history_checks"], array_keys($definitions));
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $db = $this->createStub(\Qubus\Expressive\Connection::class);
            $db->method("quoteIdentifier")->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"',
            );
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                new $class($db)->create($definitions["sis_history_checks"]),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("shc_subject_page", $sql);
            self::assertStringContainsString("checked_at", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/sis_history_events|payload|sealed|reason|password|amount|DROP |DELETE FROM/i",
                $sql,
            );
        }
        self::assertSame(
            $before,
            array_map($s->sql->rows(...), [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_history_checks",
                "sis_audit",
                "sis_access_role_permissions",
                "sis_access_user_permissions",
            ]),
        );
        $permission = $s->sql->rows(
            "sis_access_permissions",
            ["name" => "sis:history:verify"],
            1,
        )[0];
        self::assertSame(1, (int) $permission["is_system"]);
        self::assertSame(
            'Run bounded selected-record history checks and read their support receipts.',
            $permission['description']
        );
        self::assertSame(
            [],
            $s->sql->rows("sis_access_role_permissions", ["permission_id" => $permission["id"]]),
        );
        self::assertSame(
            [],
            $s->sql->rows("sis_access_user_permissions", ["permission_id" => $permission["id"]]),
        );
        $this->rejects($migration->down(...), \LogicException::class);
    }
}
