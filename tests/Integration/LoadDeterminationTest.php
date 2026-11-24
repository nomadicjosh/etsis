<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\LoadDeterminationService;
use Domain\Student\ValueObject\EnrollmentLoadPolicySelection;
use Domain\Advising\Service\AdvisorProgressAccess;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository;
use Infrastructure\Persistence\Repository\SqlEnrollmentLoadPolicyRepository;
use Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Student\ValueObject\LoadDeterminationSnapshot;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class LoadDeterminationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function service(
        SisFixture $s,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): LoadDeterminationService {
        return new LoadDeterminationService(
            $s->enrollmentLoad(),
            new SqlLoadDeterminationRepository($s->sql),
            new SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new SqlEnrollmentLoadRepository($s->sql),
            new SqlEnrollmentLoadPolicyRepository($s->sql),
            EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $this->createStub(AdvisorProgressAccess::class),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
            new \Domain\Student\Service\LoadDeterminationLedger(
                new \Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository($s->sql),
                \EventStoreFixture::history($s->connection->queryBuilder()),
            ),
        );
    }
    private function setupStudent(SisFixture $s): array
    {
        $student = $s->student();
        $pursuit = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $student->id->value])[0]["id"],
        );
        $this->policy($s);
        return [$student->id, $pursuit];
    }
    private function policy(SisFixture $s, int $full = 3000): void
    {
        $service = $s->loadPolicies();
        $r = $service->review(
            null,
            [
                "bands" => [
                    ["code" => "PART", "label" => "Part time", "min_credits_milli" => 0],
                    ["code" => "FULL", "label" => "Full time", "min_credits_milli" => $full],
                ],
            ],
            $service->view()["revision"],
            "Fictional approved credit bands.",
        );
        $service->publish(
            null,
            new EntityId($r["operation_id"]),
            $r["definition"],
            $r["revision"],
            $r["reason"],
            $r["review_token"],
        );
    }

    private function selection(SisFixture $s): EnrollmentLoadPolicySelection
    {
        $policy = $s->loadPolicies()->resolve($s->program);
        return new EnrollmentLoadPolicySelection('global', new EntityId($policy['version_id']));
    }

    public function testSelectedPublicationUsesTodaysQuantitiesAndRetainsItsBasisAfterLaterPublication(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $first = $s->loadPolicies()->resolve($s->program);
        $selection = new EnrollmentLoadPolicySelection('global', new EntityId($first['version_id']));
        $s->service->register($student, $s->section()->id);
        $this->policy($s, 6000);
        $working = $s->enrollmentLoad()->review($student, $pursuit, $s->term, 'staff');
        self::assertSame('PART', $working['classification']['code']);
        $service = $this->service($s);
        $review = $service->review($student, $pursuit, $s->term, 'Fictional authorized retained policy.', $selection);
        self::assertSame('FULL', $review['result']['classification']['code']);
        self::assertSame('selected_publication', $review['result']['policy']['basis']);
        self::assertSame(1, $review['result']['policy']['publication_version']);
        self::assertSame($selection->describe(), $review['policy_selection']);
        $this->policy($s, 9000);
        $receipt = $this->confirm($s, $student, $pursuit, $review);
        $before = $this->facts($s);
        $s->clock->time = $s->clock->time->modify('+21 minutes');
        self::assertSame($receipt, $this->confirm($s, $student, $pursuit, $review));
        self::assertSame($before, $this->facts($s));
        $owner = $service->listing($student, $pursuit, $s->term, 'owner')['items'][0];
        $visiblePolicy = $review['result']['policy'];
        unset($visiblePolicy['version_id']);
        self::assertEquals($visiblePolicy, $owner['policy']);
        self::assertArrayNotHasKey('reason', $owner);
        self::assertArrayNotHasKey('sources', $owner);
        $working = $s->enrollmentLoad()->review($student, $pursuit, $s->term, 'owner');
        self::assertSame('PART', $working['classification']['code']);
        $current = $service->review($student, $pursuit, $s->term, 'Fictional current publication.');
        self::assertSame('current_policy', $current['result']['policy']['basis']);
        self::assertSame(3, $current['result']['policy']['publication_version']);
    }

    public function testFreshAndAcceptedConfirmationsBindTheExplicitSelectionIncludingItsAbsence(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $selection = $this->selection($s);
        $r = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional selected publication.', $selection);
        $without = $r;
        $without['policy_selection'] = null;
        $before = $this->facts($s);
        $this->reject(fn() => $this->confirm($s, $student, $pursuit, $without), ConcurrentModificationException::class);
        self::assertSame($before, $this->facts($s));
        $this->confirm($s, $student, $pursuit, $r);
        $before = $this->facts($s);
        $this->reject(fn() => $this->confirm($s, $student, $pursuit, $without), AccessDeniedException::class);
        $changed = $r;
        $changed['policy_selection'] = ['source' => 'program', 'version_id' => $selection->versionId->value];
        $this->reject(fn() => $this->confirm($s, $student, $pursuit, $changed), AccessDeniedException::class);
        self::assertSame($before, $this->facts($s));
        $current = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional current policy.');
        $this->confirm($s, $student, $pursuit, $current);
        $current['policy_selection'] = $selection->describe();
        $this->reject(fn() => $this->confirm($s, $student, $pursuit, $current), AccessDeniedException::class);
    }

    public function testHistoricalChoiceRequiresIndependentPolicyAuthorityEvenOnAcceptedRetries(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $selection = $this->selection($s);
        $r = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional selected publication.', $selection);
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read', 'sis:load:determine'];
        $current = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional current policy.');
        self::assertSame('current_policy', $current['result']['policy']['basis']);
        $before = $this->facts($s);
        foreach (
            [
            fn() => $s->enrollmentLoad()->policyChoices($student, $pursuit, 'global'),
            fn() => $this->service($s)->review($student, $pursuit, $s->term, 'Fictional selection.', $selection),
            fn() => $this->confirm($s, $student, $pursuit, $r),
            ] as $action
        ) {
            $this->reject($action, AccessDeniedException::class);
        }
        self::assertSame($before, $this->facts($s));
        $s->access->grants[] = 'sis:load:policy';
        $this->confirm($s, $student, $pursuit, $r);
        array_pop($s->access->grants);
        $before = $this->facts($s);
        $this->reject(fn() => $this->confirm($s, $student, $pursuit, $r), AccessDeniedException::class);
        $r['policy_selection'] = null;
        $this->reject(fn() => $this->confirm($s, $student, $pursuit, $r), AccessDeniedException::class);
        self::assertSame($before, $this->facts($s));
    }

    public function testSelectionCannotFallBackFromUnknownWrongScopeOrClearedPublications(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $first = $s->loadPolicies()->resolve($s->program);
        $service = $s->loadPolicies();
        $clear = $service->review(null, null, $service->view()['revision'], 'Fictional cleared institution policy.');
        $service->publish(
            null,
            new EntityId($clear['operation_id']),
            null,
            $clear['revision'],
            $clear['reason'],
            $clear['review_token'],
        );
        $before = $this->facts($s);
        foreach (
            [
            new EnrollmentLoadPolicySelection('global', EntityId::generate()),
            new EnrollmentLoadPolicySelection('program', new EntityId($first['version_id'])),
            new EnrollmentLoadPolicySelection('global', new EntityId($clear['operation_id'])),
            ] as $selection
        ) {
            $this->reject(
                fn() => $this->service($s)->review(
                    $student,
                    $pursuit,
                    $s->term,
                    'Fictional invalid selection.',
                    $selection,
                ),
                ValidationException::class,
            );
        }
        self::assertSame($before, $this->facts($s));
        $valid = new EnrollmentLoadPolicySelection('global', new EntityId($first['version_id']));
        $review = $this->service($s)->review(
            $student,
            $pursuit,
            $s->term,
            'Fictional retained policy despite clear.',
            $valid,
        );
        self::assertSame('selected_publication', $review['result']['policy']['basis']);
        $invalidSelections = [
            [], ['source' => 'global'], ['source' => 'other', 'version_id' => $first['version_id']],
            ['source' => 'global', 'version_id' => 'malformed'],
            ['source' => 'global', 'version_id' => $first['version_id'], 'program_id' => $s->program->value],
        ];
        foreach ($invalidSelections as $invalid) {
            $this->reject(fn() => EnrollmentLoadPolicySelection::fromArray($invalid), ValidationException::class);
        }
    }

    public function testSelectedPolicyConfirmationRechecksQuantitiesAndPolicyAuthorityAfterAudit(): void
    {
        foreach (['quantity', 'authority'] as $case) {
            $s = new SisFixture();
            [$student, $pursuit] = $this->setupStudent($s);
            $selection = $this->selection($s);
            $r = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional selection.', $selection);
            $section = $s->section();
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $student, $section, $case): void {
                if ($case === 'quantity') {
                    $s->service->register($student, $section->id);
                } else {
                    $s->access->staff = false;
                    $s->access->grants = ['sis:student:read', 'sis:load:determine'];
                }
            });
            $before = $this->facts($s);
            $this->reject(
                fn() => $this->service($s, $audit)->confirm(
                    $student,
                    $pursuit,
                    $s->term,
                    new EntityId($r['operation_id']),
                    $r['revision'],
                    $r['reason'],
                    $r['review_token'],
                    $selection
                ),
                $case === 'quantity' ? ConcurrentModificationException::class : AccessDeniedException::class
            );
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testEarlierSnapshotsRemainExactAndNewPublicationMetadataFailsClosed(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $r = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional current policy.');
        $this->confirm($s, $student, $pursuit, $r);
        $root = \Domain\Student\EnrollmentLoadDetermination::reconstituteFromEventStream(
            EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\Student\ValueObject\LoadDeterminationId($r['operation_id']),
            ),
        );
        $data = $root->snapshot()->data;
        $legacy = $data;
        foreach (['basis', 'publication_version', 'published_at'] as $field) {
            unset($legacy['policy'][$field]);
        }
        $snapshot = new LoadDeterminationSnapshot($legacy);
        self::assertSame($legacy, $snapshot->data);
        self::assertArrayNotHasKey('basis', $snapshot->view()['policy']);
        $invalidFields = [
            'basis' => 'inferred', 'publication_version' => 0, 'published_at' => '2026-02-30T12:00:00+00:00',
        ];
        foreach ($invalidFields as $field => $invalid) {
            $broken = $data;
            $broken['policy'][$field] = $invalid;
            $this->reject(fn() => new LoadDeterminationSnapshot($broken), CorruptHistoryException::class);
        }
        unset($data['policy']['published_at']);
        $this->reject(fn() => new LoadDeterminationSnapshot($data), CorruptHistoryException::class);
    }
    private function confirm(SisFixture $s, EntityId $student, EntityId $pursuit, array $r): array
    {
        return $this->service($s)->confirm(
            $student,
            $pursuit,
            $s->term,
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["reason"],
            $r["review_token"],
            isset($r['policy_selection']) ? EnrollmentLoadPolicySelection::fromArray($r['policy_selection']) : null,
        );
    }
    public function testRetainedPolicyAndRegisteredCreditsSurviveLaterDropsAndPolicyChanges(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $section = $s->section();
        $s->service->register($student, $section->id);
        $service = $this->service($s);
        $r = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional current-load determination.",
        );
        self::assertSame(3000, $r["result"]["credits_milli"]);
        self::assertSame("FULL", $r["result"]["classification"]["code"]);
        $result = $this->confirm($s, $student, $pursuit, $r);
        self::assertSame("recorded", $result["status"]);
        $root = \Domain\Student\EnrollmentLoadDetermination::reconstituteFromEventStream(
            EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\Student\ValueObject\LoadDeterminationId($r['operation_id']),
            ),
        );
        self::assertSame(1, $root->playhead());
        self::assertSame(3000, $root->snapshot()->creditsMilli);
        $before = $s->sql->rows("sis_load_determinations");
        $drop = $s->drops->review($student, $section->id);
        $s->drops->commit(
            $student,
            $section->id,
            new EntityId($drop["enrollment_id"]),
            $drop["version"],
            new EntityId($drop["operation_id"]),
            $drop["revision"],
            $drop["effective_on"],
        );
        $this->policy($s, 6000);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        self::assertSame($result, $this->confirm($s, $student, $pursuit, $r));
        self::assertSame($before, $s->sql->rows("sis_load_determinations"));
        $page = $service->listing($student, $pursuit, $s->term, "staff");
        self::assertSame("FULL", $page["items"][0]["classification"]["code"]);
        self::assertSame(3000, $page["items"][0]["credits_milli"]);
        $next = $service->review($student, $pursuit, $s->term, "Fictional later determination.");
        $this->confirm($s, $student, $pursuit, $next);
        self::assertCount(2, $service->listing($student, $pursuit, $s->term, "staff")["items"]);
        self::assertSame(
            0,
            $service->listing($student, $pursuit, $s->term, "staff")["items"][0]["credits_milli"],
        );
    }

    private function reject(callable $action, string $class): void
    {
        try {
            $action();
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);
            return;
        }
        self::fail("An invalid load decision was accepted.");
    }
    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_load_determinations",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_audit",
        ]);
    }
    public function testFreshConfirmationStopsOnChangedRegistrationsPolicyOrDateWithoutPartialRecords(): void
    {
        foreach (["registration", "policy", "date", "expiry"] as $change) {
            $s = new SisFixture();
            [$student, $pursuit] = $this->setupStudent($s);
            $r = $this->service($s)->review($student, $pursuit, $s->term, "Fictional decision.");
            if ($change === "registration") {
                $s->service->register($student, $s->section()->id);
            } elseif ($change === "policy") {
                $this->policy($s, 6000);
            } elseif ($change === "date") {
                $s->clock->time = $s->clock->time->modify("+1 day");
            } else {
                $s->clock->time = $s->clock->time->modify("+20 minutes");
            }
            $before = $this->facts($s);
            $this->reject(
                fn() => $this->confirm($s, $student, $pursuit, $r),
                ConcurrentModificationException::class,
            );
            self::assertSame($before, $this->facts($s));
        }
    }
    public function testNoRegistrationsDiffersFromAnActiveZeroCreditRegistrationAndRetainsLocalDate(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $s->clock->time = new \DateTimeImmutable("2026-08-10T23:30:00-07:00");
        $service = $this->service($s);
        $empty = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional no-registration observation.",
        );
        self::assertNull($empty["result"]["classification"]);
        $this->confirm($s, $student, $pursuit, $empty);
        $section = $s->section(fee: 0);
        $s->sql->update("sis_courses", ["credits_milli" => 0], ["id" => $section->courseId->value]);
        $s->service->register($student, $section->id);
        $zero = $service->review(
            $student,
            $pursuit,
            $s->term,
            "Fictional zero-credit registration.",
        );
        self::assertSame("PART", $zero["result"]["classification"]["code"]);
        self::assertSame(0, $zero["result"]["credits_milli"]);
        $this->confirm($s, $student, $pursuit, $zero);
        $page = $service->listing($student, $pursuit, $s->term, "owner");
        self::assertSame("2026-08-10", $page["items"][0]["as_of_date"]);
        self::assertSame([1, 0], array_column($page["items"], "enrollment_count"));
        self::assertArrayNotHasKey("items", $page["items"][0]);
        $json = json_encode($page);
        foreach (
            ["enrollment_id", "credit_id", "reason", "actor", "policy_version_id"] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame(
            "2026-08-11 06:30:00",
            $s->sql->rows("sis_load_determinations")[0]["recorded_at"],
        );
    }
    public function testCurrentTermAndPublishedPolicyAreRequiredAndMissingTrackedSourcesAreRejected(): void
    {
        foreach (["past", "future", "policy", "missing_source"] as $case) {
            $s = new SisFixture();
            [$student, $pursuit] = $this->setupStudent($s);
            if ($case === "past") {
                $s->clock->time = new \DateTimeImmutable("2027-02-01T12:00:00Z");
            }
            if ($case === "future") {
                $s->clock->time = new \DateTimeImmutable("2025-02-01T12:00:00Z");
            }
            if ($case === "policy") {
                $p = $s->loadPolicies();
                $r = $p->review(
                    null,
                    null,
                    $p->view()["revision"],
                    "Fictional classification disabled.",
                );
                $p->publish(
                    null,
                    new EntityId($r["operation_id"]),
                    null,
                    $r["revision"],
                    $r["reason"],
                    $r["review_token"],
                );
            }
            if ($case === "missing_source") {
                $e = $s->service->register($student, $s->section()->id);
                $s->sql->execute("DELETE FROM sis_enrollments WHERE id = ?", [$e->id->value]);
            }
            $this->reject(
                fn() => $this->service($s)->review(
                    $student,
                    $pursuit,
                    $s->term,
                    "Fictional decision.",
                ),
                ValidationException::class,
            );
            self::assertSame([], $s->sql->rows("sis_load_determinations"));
        }
    }
    public function testAuditFailureAuthorityExpiryAndFinalSourceChangesRollbackTheDecision(): void
    {
        foreach (["audit", "authority", "expiry", "source"] as $case) {
            $s = new SisFixture();
            [$student, $pursuit] = $this->setupStudent($s);
            $r = $this->service($s)->review($student, $pursuit, $s->term, "Fictional decision.");
            $section = $s->section();
            $before = $this->facts($s);
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($s, $case, $student, $section): void {
                    if ($case === "audit") {
                        throw new \RuntimeException("Fictional failed audit.");
                    }
                    if ($case === "authority") {
                        $s->access->staff = false;
                        $s->access->grants = [];
                    }
                    if ($case === "expiry") {
                        $s->clock->time = $s->clock->time->modify("+20 minutes");
                    }
                    if ($case === "source") {
                        $s->service->register($student, $section->id);
                    }
                });
            $this->reject(
                fn() => $this->service($s, $audit)->confirm(
                    $student,
                    $pursuit,
                    $s->term,
                    new EntityId($r["operation_id"]),
                    $r["revision"],
                    $r["reason"],
                    $r["review_token"],
                ),
                $case === "authority"
                    ? AccessDeniedException::class
                    : \RuntimeException::class,
            );
            self::assertSame($before, $this->facts($s));
        }
    }
    public function testIndependentAuthorityAndOwnerScopeHoldForFreshAndAcceptedConfirmations(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $r = $this->service($s)->review($student, $pursuit, $s->term, "Fictional decision.");
        $this->confirm($s, $student, $pursuit, $r);
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read"];
        $this->reject(
            fn() => $this->confirm($s, $student, $pursuit, $r),
            AccessDeniedException::class,
        );
        self::assertCount(
            1,
            $this->service($s)->listing($student, $pursuit, $s->term, "owner")["items"],
        );
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read", "sis:load:determine"];
        $this->reject(
            fn() => $this->service($s)->listing($student, $pursuit, $s->term, "owner"),
            AccessDeniedException::class,
        );
        $this->reject(
            fn() => $this->confirm($s, $student, $pursuit, $r),
            AccessDeniedException::class,
        );
    }
    public function testDamagedProjectionOrProtectedEventNeverGetsRepairedOnReadsOrRetries(): void
    {
        foreach (["quantity", "missing_row", "event"] as $case) {
            $s = new SisFixture();
            [$student, $pursuit] = $this->setupStudent($s);
            $r = $this->service($s)->review($student, $pursuit, $s->term, "Fictional decision.");
            $this->confirm($s, $student, $pursuit, $r);
            if ($case === "quantity") {
                $s->sql->update(
                    "sis_load_determinations",
                    ["registered_credits_milli" => 999],
                    ["id" => $r["operation_id"]],
                );
            } elseif ($case === "missing_row") {
                $s->sql->execute("DELETE FROM sis_load_determinations WHERE id = ?", [
                    $r["operation_id"],
                ]);
            } else {
                $head = $s->sql->rows("sis_history_streams", [
                    "aggregate_id" => $r["operation_id"],
                ])[0];
                $s->sql->update(
                    "sis_history_events",
                    ["sealed_event" => "Fictional damaged event"],
                    ["stream_id" => $head["id"]],
                );
            }
            $before = $this->facts($s);
            $this->reject(
                fn() => $this->confirm($s, $student, $pursuit, $r),
                CorruptHistoryException::class,
            );
            if ($case !== "missing_row") {
                $this->reject(
                    fn() => $this->service($s)->listing($student, $pursuit, $s->term, "staff"),
                    CorruptHistoryException::class,
                );
            }
            self::assertSame($before, $this->facts($s));
        }
    }
    private function reports(SisFixture $s): \Domain\Reporting\Service\RelationalReportService
    {
        return new \Domain\Reporting\Service\RelationalReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }
    private function reportDefinition(): array
    {
        return [
            "dataset" => "load_determinations",
            "columns" => [
                ["field" => "term_code", "label" => "Recorded term", "aggregate" => "none"],
                [
                    "field" => "determination_id",
                    "label" => "Determinations",
                    "aggregate" => "count",
                ],
                [
                    "field" => "student_id",
                    "label" => "Distinct students",
                    "aggregate" => "count_distinct",
                ],
                [
                    "field" => "registered_credits",
                    "label" => "Recorded credits",
                    "aggregate" => "sum",
                ],
            ],
        ];
    }
    public function testReportsKeepDeterminationsSeparateAndSharedDefinitionsRequireCurrentSourceAuthority(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        $section = $s->section();
        $s->sql->update('sis_courses', ['credits_milli' => 3125], ['id' => $section->courseId->value]);
        $s->service->register($student, $section->id);
        for ($i = 0; $i < 2; $i++) {
            $r = $this->service($s)->review(
                $student,
                $pursuit,
                $s->term,
                "Fictional recorded review.",
            );
            $this->confirm($s, $student, $pursuit, $r);
        }
        $reports = $this->reports($s);
        $d = $this->reportDefinition();
        $before = $s->sql->rows("sis_history_events");
        $rows = $reports->preview($d, 0)["items"];
        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]["c1"]);
        self::assertSame(1, $rows[0]["c2"]);
        self::assertSame(6.25, $rows[0]["c3"]);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional retained load", $d);
        self::assertSame($rows, $reports->run($id, 0)["items"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $catalog = \Infrastructure\Reporting\AcademicReportSchema::datasets()["load_determinations"];
        foreach (
            [
                "reason",
                "actor_id",
                "enrollment_id",
                "credit_id",
                "policy_version_id",
                "sealed_event",
            ] as $private
        ) {
            self::assertArrayNotHasKey($private, $catalog["fields"]);
        }
        $s->reportLifecycles()->configure($id, EntityId::generate(), 0, 'static', null);
        $later = $this->service($s)->review($student, $pursuit, $s->term, 'Fictional later determination.');
        $this->confirm($s, $student, $pursuit, $later);
        self::assertSame(3, $reports->preview($d, 0)['items'][0]['c1']);
        self::assertSame($rows, $reports->run($id, 0)['items']);
        $recipient = $s->user();
        new \Infrastructure\Persistence\Repository\SqlReportRepository($s->sql, $s->clock)->share(
            $id,
            $recipient,
        );
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:run", "sis:reporting:manage", "sis:student:read"];
        $this->reject(
            fn() => $reports->run($id, 0),
            AccessDeniedException::class,
        );
        self::assertNotContains(
            "load_determinations",
            array_column($reports->catalog()["datasets"], "id"),
        );
        $s->access->grants[] = "sis:load:determine";
        self::assertSame($rows, $reports->run($id, 0)["items"]);
        $s->access->grants = ["sis:reporting:run", "sis:load:determine"];
        $this->reject(
            fn() => $reports->run($id, 0),
            AccessDeniedException::class,
        );
    }
    public function testReportTotalsRejectRelatedRegistrationFanoutAndCompileForEveryDriver(): void
    {
        $schema = \Infrastructure\Reporting\AcademicReportSchema::datasets()["load_determinations"];
        $d = $this->reportDefinition();
        $d["joins"] = ["registration" => "left"];
        $this->reject(
            fn() => new \Domain\Reporting\ValueObject\ReportQuery($d, $schema),
            ValidationException::class,
        );
        unset($d["joins"]);
        foreach (["mysql", "pgsql", "sqlite", "sqlsrv", "oci"] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $pdo = $this->createStub(\PDO::class);
            $pdo->method("getAttribute")->willReturn($driver);
            $connection
                ->method(\PHPUnit\Framework\MockObject\Runtime\PropertyHook::get("pdo"))
                ->willReturn($pdo);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method("getConnection")->willReturn($connection);
            $plan = new \Infrastructure\Reporting\ReportSelectCompiler(
                new \Infrastructure\Persistence\SqlRecords($db),
            )->compile(new \Domain\Reporting\ValueObject\ReportQuery($d, $schema));
            self::assertStringContainsString("sis_load_determinations", $plan["sql"]);
            self::assertStringContainsString("COUNT(DISTINCT", $plan["sql"]);
            self::assertStringNotContainsString("sis_history_events", $plan["sql"]);
        }
    }
    public function testRecordedHistoryPagesAndAcceptedRetriesRemainAvailableAfterPursuitClosure(): void
    {
        $s = new SisFixture();
        [$student, $pursuit] = $this->setupStudent($s);
        for ($i = 0; $i < 11; $i++) {
            $r = $this->service($s)->review(
                $student,
                $pursuit,
                $s->term,
                "Fictional separate decision.",
            );
            $receipt = $this->confirm($s, $student, $pursuit, $r);
        }
        // Fictional changed projection tests read scope; it does not invent a historical lifecycle event.
        $s->sql->update("sis_student_programs", ["status" => "closed"], ["id" => $pursuit->value]);
        self::assertSame($receipt, $this->confirm($s, $student, $pursuit, $r));
        $page = $this->service($s)->listing($student, $pursuit, $s->term, "owner");
        self::assertCount(10, $page["items"]);
        self::assertNotNull($page["next_after"]);
        self::assertCount(
            1,
            $this->service($s)->listing(
                $student,
                $pursuit,
                $s->term,
                "owner",
                new EntityId($page["next_after"]),
            )["items"],
        );
        self::assertNotEmpty($this->service($s)->terms($student, $pursuit, "owner")["items"]);
        $this->reject(
            fn() => $this->service($s)->review(
                $student,
                $pursuit,
                $s->term,
                "Fictional new decision.",
            ),
            ValidationException::class,
        );
    }

    public function testDeterminationMigrationIsPortableAndPreservesRecordedFactsAndGrants(): void
    {
        $s = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $build) use (&$definitions): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $definitions[] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        $migration = new \CreateSisLoadDeterminations("load-schema");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "migration",
                ),
            ]),
        );
        $before = $s->sql->rows("sis_history_events");
        $grants = $s->sql->rows("sis_access_role_permissions");
        $migration->up();
        $migration->up();
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($grants, $s->sql->rows("sis_access_role_permissions"));
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(
                new $class($connection)->create($definitions[0]),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("sld_pursuit_fk", $sql);
            self::assertStringContainsString("registered_credits_milli", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/AUTO_INCREMENT|sis_history_events|DELETE FROM/i",
                $sql,
            );
        }
    }
}
