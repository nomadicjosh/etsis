<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Import\Service\ImportQueueService;
use Domain\Import\Service\ImportWorker;
use Domain\Import\Service\PersonImportService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Import\LeaguePersonCsvMapper;
use Infrastructure\Import\ReviewedImportBatchProcessor;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlImportQueueRepository;
use Infrastructure\Persistence\Repository\SqlPersonImportRepository;
use Infrastructure\Security\SqlLoginAccountStateReader;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class BackgroundImportTest extends TestCase
{
    private function context(?AuditTrail $audit = null): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $f = new SisFixture();
        $registration = $f->registrationService();
        $f->sql->update(
            "users",
            [
                "token" => "fictional-command-token",
                "password" => "fictional-disabled-test-hash",
            ],
            ["user_id" => $f->access->actor->value],
        );
        $roles = new SqlAccessRepository($f->sql);
        foreach (["sis:import:manage", "sis:person:manage"] as $name) {
            $permission = $f->sql->one("sis_access_permissions", [
                "name" => $name,
            ]);
            $roles->assign(
                GrantKind::UserPermission,
                $f->access->actor,
                new EntityId($permission["id"]),
                true,
            );
        }
        $people = new PersonImportService(
            new LeaguePersonCsvMapper(),
            new SqlPersonImportRepository($f->sql, $f->admissions),
            $registration,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $repo = new SqlImportQueueRepository($f->sql);
        $queue = new ImportQueueService(
            $repo,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $processor = new ReviewedImportBatchProcessor(
            $f->imports(),
            $people,
            $roles,
            new SqlLoginAccountStateReader($f->sql),
        );
        $worker = new ImportWorker(
            $repo,
            $processor,
            $roles,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        return [$f, $people, $queue, $worker, $repo];
    }

    private function review($people, $queue, int $count = 21): array
    {
        $csv = "Given,Family,Email\n";
        for ($i = 0; $i < $count; $i++) {
            $csv .= "Fictional$i,Intake,queued$i@example.invalid\n";
        }
        $request = [
            "csv" => $csv,
            "mapping" => ["given_name" => 0, "family_name" => 1, "email" => 2],
            "delimiter" => ",",
            "reason" => "Fictional new-person intake",
        ];
        return $queue->stage("people", $request, $people->analyze(...$request));
    }

    public function testConfirmationOnlyQueuesAndServerBatchesResumeWithOneDisposableCompletionNotice(): void
    {
        [$f, $people, $queue, $worker] = $this->context();
        $review = $this->review($people, $queue);
        $submitted = $queue->submit($review["review_token"]);
        self::assertSame("queued", $submitted["state"]);
        self::assertSame([], $f->sql->rows("sis_persons"));
        self::assertStringNotContainsString(
            "@example.invalid",
            json_encode($f->sql->rows("sis_import_artifacts")),
        );
        self::assertSame($submitted, $queue->submit($review["review_token"]));
        self::assertSame("processed", $worker->runOne());
        self::assertCount(10, $f->sql->rows("sis_persons"));
        self::assertSame(
            10,
            $queue->status(new EntityId($review["job_id"]))["processed"],
        );
        self::assertSame([], $queue->notices()["items"]);
        self::assertSame("processed", $worker->runOne());
        self::assertSame("completed", $worker->runOne());
        self::assertCount(21, $f->sql->rows("sis_persons"));
        self::assertCount(21, $f->sql->rows("sis_account_setup"));
        self::assertSame([], $f->sql->rows("sis_import_artifacts"));
        $notices = $queue->notices()["items"];
        self::assertCount(1, $notices);
        $queue->readNotice(new EntityId($notices[0]["id"]));
        self::assertSame([], $queue->notices()["items"]);
        self::assertCount(1, $queue->notices(false)["items"]);
        $queue->deleteNotice(new EntityId($notices[0]["id"]));
        $f->clock->time = $f->clock->now()->modify("+2 days");
        self::assertSame(
            "completed",
            $queue->submit($review["review_token"])["state"],
        );
        self::assertSame("idle", $worker->runOne());
        self::assertSame([], $f->sql->rows("sis_import_notices"));
    }

    public function testChangedLaterRowsStopWithoutRollingBackEarlierCommittedBatchesOrWritingBeyondCursor(): void
    {
        [$f, $people, $queue, $worker] = $this->context();
        $review = $this->review($people, $queue);
        $queue->submit($review["review_token"]);
        self::assertSame("processed", $worker->runOne());
        $f->registrationService()->staff(
            EntityId::generate(),
            "Other",
            "Intake",
            "queued14@example.invalid",
        );
        $before = $f->sql->rows("sis_history_events");
        self::assertSame("needs_attention", $worker->runOne());
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(11, $f->sql->rows("sis_persons"));
        $status = $queue->status(new EntityId($review["job_id"]));
        self::assertSame(10, $status["processed"]);
        self::assertSame("conflict", $status["error_code"]);
        self::assertSame([], $f->sql->rows("sis_import_artifacts"));
        self::assertCount(1, $queue->notices()["items"]);
    }

    public function testWorkerChecksCurrentAuthorityWithoutUsingOrChangingTheBrowserPrincipal(): void
    {
        [$f, $people, $queue, $worker] = $this->context();
        $review = $this->review($people, $queue, 1);
        $owner = $f->access->actor;
        $queue->submit($review["review_token"]);
        $f->access->actor = $f->user();
        self::assertSame("completed", $worker->runOne());
        self::assertNotSame($owner->value, $f->access->actor->value);
        self::assertSame([], $queue->notices()["items"]);
        try {
            $queue->status(new EntityId($review["job_id"]));
            self::fail("Foreign import status was exposed.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->actor = $owner;
        $next = $this->review($people, $queue, 2);
        // The existing first email must block a new review rather than auto-linking.
        self::assertFalse($next["ready"]);
        $request = [
            "csv" => "G,F,E\nNew,Person,new@example.invalid\n",
            "mapping" => ["given_name" => 0, "family_name" => 1, "email" => 2],
            "delimiter" => ",",
            "reason" => "Fictional intake",
        ];
        $next = $queue->stage(
            "people",
            $request,
            $people->analyze(...$request),
        );
        $queue->submit($next["review_token"]);
        $f->sql->update(
            "users",
            ["sign_in_enabled" => 0],
            ["user_id" => $owner->value],
        );
        self::assertSame("needs_attention", $worker->runOne());
        self::assertSame(
            "permission",
            $queue->status(new EntityId($next["job_id"]))["error_code"],
        );
        self::assertCount(1, $f->sql->rows("sis_persons"));
    }

    public function testExpiredLeasesRecoverButExpiredSourcesAndAbandonedReviewsAreDisposed(): void
    {
        [$f, $people, $queue, $worker, $repo] = $this->context();
        $review = $this->review($people, $queue, 1);
        $queue->submit($review["review_token"]);
        $id = new EntityId($review["job_id"]);
        $repo->claim($id, 1, str_repeat("a", 32), "2026-08-10T16:00:00.000000");
        self::assertSame("completed", $worker->runOne());
        $abandoned = $this->review($people, $queue, 2); // not ready because first row exists
        self::assertFalse($abandoned["ready"]);
        $request = [
            "csv" => "G,F,E\nNew,Person,expired@example.invalid\n",
            "mapping" => ["given_name" => 0, "family_name" => 1, "email" => 2],
            "delimiter" => ",",
            "reason" => "Fictional intake",
        ];
        $unused = $queue->stage(
            "people",
            $request,
            $people->analyze(...$request),
        );
        $queued = $queue->stage(
            "people",
            $request,
            $people->analyze(...$request),
        );
        $queue->submit($queued["review_token"]);
        $f->clock->time = $f->clock->now()->modify("+2 days");
        self::assertSame("needs_attention", $worker->runOne());
        self::assertNull($repo->job(new EntityId($unused["job_id"])));
        self::assertSame(
            "expired",
            $queue->status(new EntityId($queued["job_id"]))["error_code"],
        );
        self::assertSame([], $f->sql->rows("sis_import_artifacts"));
    }

    public function testCatalogFilesLargerThanOneBatchUseTheSameQueueAndPreserveDraftPublicationBoundary(): void
    {
        [$f, , $queue, $worker] = $this->context();
        $csv = "ID,Code,Title,Credits\n";
        for ($i = 0; $i < 101; $i++) {
            $csv .= "external$i,CRS.$i,Fictional course $i,3\n";
        }
        $request = [
            "source" => "fictional",
            "csv" => $csv,
            "mapping" => [
                "external_id" => 0,
                "code" => 1,
                "title" => 2,
                "credits" => 3,
            ],
        ];
        $review = $queue->stage(
            "course",
            $request,
            $f->imports()->preview(...$request),
        );
        $queue->submit($review["review_token"]);
        self::assertSame("processed", $worker->runOne());
        self::assertCount(100, $f->sql->rows("sis_courses"));
        self::assertSame("completed", $worker->runOne());
        self::assertCount(101, $f->sql->rows("sis_courses"));
        self::assertSame([], $f->sql->rows("sis_courses", ["active" => 1]));
        self::assertSame(
            101,
            $queue->status(new EntityId($review["job_id"]))["imported"],
        );
    }
    public function testCompletionAuditFailureRollsBackTheBatchProgressHistoryAndNoticeTogether(): void
    {
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function ($actor, string $action): void {
            if ($action === 'import.completed') {
                throw new \RuntimeException('Fictional required completion audit failure');
            }
        });
        [$f, $people, $queue, $worker] = $this->context($audit);
        $review = $this->review($people, $queue, 2);
        $queue->submit($review['review_token']);
        $tables = ['users', 'sis_persons', 'sis_account_setup', 'sis_person_imports',
            'sis_history_streams', 'sis_history_events', 'sis_history_operations', 'sis_audit'];
        $before = array_map($f->sql->rows(...), $tables);
        self::assertSame('needs_attention', $worker->runOne());
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        $job = $queue->status(new EntityId($review['job_id']));
        self::assertSame(0, $job['processed']);
        self::assertSame(0, $job['imported']);
        self::assertCount(1, $queue->notices()['items']);
        self::assertSame([], $f->sql->rows('sis_import_artifacts'));
    }

    public function testAnEncryptedArtifactCannotBeSubstitutedFromAnotherReviewedJob(): void
    {
        [$f, $people, $queue, $worker, $repo] = $this->context();
        $first = $this->review($people, $queue, 1);
        $other = $this->review($people, $queue, 1);
        $source = $repo->artifact(new EntityId($other['job_id']));
        $queue->submit($first['review_token']);
        $f->sql->update('sis_import_artifacts', ['encrypted_payload' => $source['encrypted_payload'],
            'payload_hash' => $source['payload_hash']], ['id' => $first['job_id']]);
        self::assertSame('needs_attention', $worker->runOne());
        self::assertSame('source_unavailable', $queue->status(new EntityId($first['job_id']))['error_code']);
        self::assertSame([], $f->sql->rows('sis_persons'));
    }

    public function testLargePeopleReviewIsBoundedAndBulkCheckedWithoutCreatingIdentities(): void
    {
        [$f, $people, $queue] = $this->context();
        $review = $this->review($people, $queue, 5000);
        self::assertTrue($review['ready']);
        self::assertCount(5000, $review['rows']);
        self::assertSame([], $f->sql->rows('sis_persons'));
        $this->expectException(\InvalidArgumentException::class);
        $this->review($people, $queue, 5001);
    }
}
