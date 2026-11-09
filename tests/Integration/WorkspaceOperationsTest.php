<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Defuse\Crypto\Key;
use Domain\Access\Repository\AccessRepository;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Service\OperationsService;
use Domain\Workspace\Service\WorkspaceService;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\Repository\SqlCampaignRepository;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlOperationsRepository;
use Infrastructure\Persistence\Repository\SqlWorkspaceRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Config\ConfigContainer;

final class WorkspaceOperationsTest extends TestCase
{
    private function campaigns(SisFixture $f, ?MailTransport $transport = null): CampaignService
    {
        $config = $this->createStub(ConfigContainer::class);
        $config->method('getConfigKey')->willReturn(Key::createNewRandomKey()->saveToAsciiSafeString());
        if ($transport === null) {
            $transport = $this->createMock(MailTransport::class);
            $transport->expects(self::never())->method('send');
        }

        return new CampaignService(
            new SqlCampaignRepository($f->sql),
            new DefuseMessageCipher($config),
            $transport,
            $f->access,
            $f->transactions,
            $f->audit
        );
    }

    private function operations(SisFixture $f, ?CampaignService $campaigns = null, array $permissions = []): OperationsService
    {
        $access = $this->createStub(AccessRepository::class);
        $access->method('allows')->willReturnCallback(static fn (string $user, string $permission): bool =>
            in_array($permission, $permissions, true));

        return new OperationsService(
            new SqlOperationsRepository($f->sql),
            $f->access,
            $access,
            $f->registration,
            $f->admissions,
            $campaigns ?? $this->campaigns($f)
        );
    }

    private function editor(SisFixture $f): CatalogEditor
    {
        return new CatalogEditor(
            new SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit
        );
    }

    public function testAssignedFacultyCanReadTheirRosterButNotAnotherSection(): void
    {
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(10, 0, $f->access->actor);
        $other = $f->section(10, 0, $f->user());
        $f->service->register($student->id, $section->id, true);
        $f->access->staff = false;
        $f->access->grants = ['admin:dashboard'];
        $service = $this->operations($f);
        $page = $service->page('roster', $section->id);
        self::assertTrue($page['can_grade']);
        self::assertSame($student->id->value, $page['items'][0]['student_id']);
        self::assertArrayHasKey('grade_version', $page['items'][0]);
        $this->expectException(AccessDeniedException::class);
        $service->page('roster', $other->id);
    }

    public function testRegistrarRosterDoesNotImplyGradebookOrStudentFinancialAccess(): void
    {
        $f = new SisFixture();
        $student = $f->student($f->user());
        $section = $f->section(10, 0, $f->user());
        $f->service->register($student->id, $section->id, true);
        $f->access->staff = false;
        $f->access->grants = ['admin:dashboard', 'sis:registration:manage'];
        $service = $this->operations($f, permissions: ['sis:registration:manage']);
        $page = $service->page('roster', $section->id);
        self::assertFalse($page['can_grade']);
        self::assertArrayNotHasKey('grade_version', $page['items'][0]);
        self::assertArrayNotHasKey('grade_code', $page['items'][0]);
        self::assertSame($section->code, $service->page('enrollments', $student->id)['items'][0]['section_code']);
        $this->expectException(AccessDeniedException::class);
        $service->page('assignments', $section->id);
    }

    public function testContextPagingIsBoundedAndDoesNotDuplicateRows(): void
    {
        $f = new SisFixture();
        $section = $f->section(10, 0, $f->access->actor);
        for ($i = 0; $i < 55; ++$i) {
            $f->sql->insert('sis_assignments', ['id' => EntityId::generate()->value,
                'section_id' => $section->id->value, 'title' => 'Fictional assignment ' . $i,
                'possible_hundredths' => 1000, 'due_on' => '2026-08-12']);
        }
        $service = $this->operations($f);
        $first = $service->page('assignments', $section->id);
        $second = $service->page('assignments', $section->id, new EntityId($first['next_after']));
        self::assertCount(50, $first['items']);
        self::assertCount(5, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(55, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
    }

    public function testTeachingDirectoryIsScopedToTheSignedInAccount(): void
    {
        $f = new SisFixture();
        $mine = $f->section(10, 0, $f->access->actor);
        $f->section(10, 0, $f->user());
        $repo = new SqlWorkspaceRepository($f->sql, $f->gradeCatalog());
        self::assertSame([$mine->id->value], array_column($repo->records('teaching', $f->access->actor, '', null)['items'], 'id'));
        foreach (['grades', 'billing', 'ledger', 'institutions', 'external', 'equivalencies', 'faculty'] as $dataset) {
            self::assertArrayHasKey($dataset, WorkspaceService::DATASETS);
            self::assertArrayHasKey('items', $repo->records($dataset, null, '', null));
        }
    }

    public function testCatalogEditPreservesAcademicSnapshotAndRejectsStaleWrite(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(10, 0);
        $f->service->register($student->id, $section->id, true);
        $editor = $this->editor($f);
        $original = $editor->view('courses', $section->courseId);
        $credit = $f->sql->rows('sis_academic_credits')[0];
        $editor->course(new Course($section->courseId, 'MATH.101', 'Revised course', 4500), $original['revision']);
        self::assertSame('MATH.101', $editor->view('courses', $section->courseId)['code']);
        self::assertSame($credit, $f->sql->rows('sis_academic_credits')[0]);
        try {
            $editor->course(new Course($section->courseId, 'MATH.102', 'Stale edit', 3000), $original['revision']);
            self::fail('A stale edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('MATH.101', $editor->view('courses', $section->courseId)['code']);
        }
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $editor->view('courses', $section->courseId);
    }

    public function testSectionEditCannotReduceCapacityBelowActiveEnrollment(): void
    {
        $f = new SisFixture();
        $section = $f->section(10, 0);
        foreach (range(1, 2) as $_) {
            $f->service->register($f->student()->id, $section->id, true);
        }
        $editor = $this->editor($f);
        $original = $editor->view('sections', $section->id);
        $this->expectException(DomainException::class);
        $editor->section(new Section(
            $section->id,
            $section->courseId,
            $section->termId,
            null,
            $section->code,
            1,
            true,
            true,
            new Money(0, 'USD')
        ), $original['revision']);
    }

    public function testCampaignReadAndDeliveryQueueEnforceOwnershipAndNeverSend(): void
    {
        $f = new SisFixture();
        $campaigns = $this->campaigns($f);
        $campaign = $campaigns->create('Fictional campus update', 'Fictional announcement for a test.');
        $actor = $f->access->actor;
        $f->sql->update('users', ['email' => 'reader@example.invalid'], ['user_id' => $actor->value]);
        $campaigns->queue($campaign->id, [$actor]);
        self::assertSame('Fictional campus update', $campaigns->show($campaign->id)['subject']);
        $service = $this->operations($f, $campaigns);
        self::assertCount(1, $service->page('deliveries', $campaign->id)['items']);
        self::assertStringNotContainsString('Fictional announcement', json_encode($f->sql->rows('sis_campaigns')));
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['admin:dashboard', 'sis:communications:manage'];
        $this->expectException(AccessDeniedException::class);
        $service->page('deliveries', $campaign->id);
    }

    public function testOperationIdsRequireFinancePermission(): void
    {
        $f = new SisFixture();
        $service = $this->operations($f);
        self::assertNotSame($service->operationId()->value, $service->operationId()->value);
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->operationId();
    }
    public function testSharedReportDirectoryIncludesOnlyOwnedOrExplicitlySharedReports(): void
    {
        $f = new SisFixture();
        $reports = new \Infrastructure\Persistence\Repository\SqlReportRepository($f->sql);
        $mine = new \Domain\Reporting\StudentReport(EntityId::generate(), $f->access->actor, 'My report');
        $shared = new \Domain\Reporting\StudentReport(EntityId::generate(), $f->user(), 'Shared report');
        $private = new \Domain\Reporting\StudentReport(EntityId::generate(), $f->user(), 'Private report');
        foreach ([$mine, $shared, $private] as $report) {
            $reports->add($report);
        }
        $reports->share($shared->id, $f->access->actor);
        $page = new SqlWorkspaceRepository($f->sql, $f->gradeCatalog())->records('sharedreports', $f->access->actor, '', null);
        self::assertEqualsCanonicalizing([$mine->id->value, $shared->id->value], array_column($page['items'], 'id'));
    }

    public function testCampaignDraftEditIsEncryptedVersionedAndStopsAfterQueueing(): void
    {
        $f = new SisFixture();
        $service = $this->campaigns($f);
        $campaign = $service->create('Fictional subject', 'Fictional initial body.');
        $service->edit($campaign->id, 'Updated fictional subject', 'Updated fictional body.', 0);
        self::assertSame(1, $service->show($campaign->id)['version']);
        self::assertSame('Updated fictional subject', $service->show($campaign->id)['subject']);
        self::assertStringNotContainsString('Updated fictional', json_encode($f->sql->rows('sis_campaigns')));
        try {
            $service->edit($campaign->id, 'Stale fictional subject', 'Stale fictional body.', 0);
            self::fail('A stale campaign edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('Updated fictional subject', $service->show($campaign->id)['subject']);
        }
        $f->sql->update('users', ['email' => 'reader@example.invalid'], ['user_id' => $f->access->actor->value]);
        $service->queue($campaign->id, [$f->access->actor]);
        $this->expectException(DomainException::class);
        $service->edit($campaign->id, 'Queued edit', 'Must not change queued content.', 2);
    }

    public function testPrerequisiteRemovalIsScopedAndAudited(): void
    {
        $f = new SisFixture();
        $first = $f->section(10, 0);
        $second = $f->section(10, 0);
        $third = $f->section(10, 0);
        $service = new \Domain\Registration\Service\RegistrarCatalogService(
            $f->registration,
            $f->access,
            $f->transactions,
            $f->audit
        );
        $service->requireCourse($first->courseId, $second->courseId);
        $service->requireCourse($third->courseId, $second->courseId);
        $service->removeRequiredCourse($first->courseId, $second->courseId);
        self::assertCount(1, $f->sql->rows('sis_prerequisites'));
        self::assertSame($third->courseId->value, $f->sql->rows('sis_prerequisites')[0]['course_id']);
        self::assertContains('course.prerequisite_removed', array_column($f->audit->forSubject($first->courseId), 'action'));
        $f->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $service->removeRequiredCourse($third->courseId, $second->courseId);
    }

    public function testDisabledMailLeavesDeliveryQueuedInsteadOfMarkingAnUncertainSend(): void
    {
        $f = new SisFixture();
        $transport = $this->createMock(MailTransport::class);
        $transport->method('assertReady')->willThrowException(new DomainException('Delivery is disabled.'));
        $transport->expects(self::never())->method('send');
        $service = $this->campaigns($f, $transport);
        $campaign = $service->create('Fictional subject', 'Fictional body.');
        $f->sql->update('users', ['email' => 'reader@example.invalid'], ['user_id' => $f->access->actor->value]);
        $deliveries = $service->queue($campaign->id, [$f->access->actor]);
        self::assertFalse($service->show($campaign->id)['can_dispatch']);
        try {
            $service->dispatch($deliveries[0]);
            self::fail('Disabled mail was dispatched.');
        } catch (DomainException) {
            self::assertSame('queued', $f->sql->one('sis_deliveries', ['id' => $deliveries[0]->value])['status']);
        }
    }

    public function testReportShareCanBeRevokedWithoutChangingOtherReportAccess(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $recipient = $f->user();
        $reports = new \Domain\Reporting\Service\ReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit
        );
        $report = $reports->create('Fictional shared report', null, null, null);
        $other = $reports->create('Another report', null, null, null);
        $reports->share($report->id, $recipient);
        $reports->share($other->id, $recipient);
        self::assertSame($recipient->value, $reports->shares($report->id)['items'][0]['user_id']);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        $f->access->grants = ['sis:reporting:run'];
        self::assertSame([], $reports->run($report->id)['items']);
        $f->access->actor = $owner;
        $f->access->staff = true;
        $reports->unshare($report->id, $recipient);
        $reports->unshare($report->id, $recipient);
        self::assertSame([], $reports->shares($report->id)['items']);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        self::assertSame([], $reports->run($other->id)['items']);
        $this->expectException(AccessDeniedException::class);
        $reports->run($report->id);
    }
}
