<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImportRecoveryServices;
use Tests\Support\WorkflowRecoveryFixture;

/** Identical bounded fictional import transactions on SQLite and opted-in native storage. */
final class ImportWorkflowRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $fixture;
    private ImportRecoveryServices $imports;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Process-loss qualification requires POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        require_once dirname(__DIR__) . '/Support/ImportRecoveryServices.php';
        $this->fixture = new WorkflowRecoveryFixture();
        $this->imports = new ImportRecoveryServices($this->fixture->sis);
        $this->imports->prepare();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
    }

    private function stableFacts(): array
    {
        // Claim state/revision/lease commit before domain work. All other facts must remain atomic.
        return array_diff_key($this->fixture->facts(), ['sis_import_jobs' => true]);
    }

    private function queued(string $target, int $count): array
    {
        $review = $this->imports->review($target, $count);
        self::assertTrue($review['ready']);
        self::assertSame('queued', $this->imports->queue()->submit($review['review_token'])['state']);
        return $review;
    }

    private function job(array $review): array
    {
        return $this->imports->queue()->status(new EntityId($review['job_id']));
    }

    private function expireLease(): void
    {
        $s = $this->fixture->sis;
        $s->clock->time = $s->clock->now()->modify('+3 minutes');
    }

    private function verifyPeople(int $count): void
    {
        $s = $this->fixture->sis;
        self::assertCount($count, $s->sql->rows('sis_persons'));
        self::assertCount($count, $s->sql->rows('sis_account_setup', ['state' => 'queued']));
        self::assertSame([], $s->sql->rows('sis_students'));
        self::assertSame([], $s->sql->rows('sis_employees'));
        foreach ($s->sql->rows('sis_persons') as $row) {
            $person = $s->admissions->person(new EntityId($row['id']));
            self::assertNotNull($person->ownerUserId);
            self::assertGreaterThan(0, $s->profileLedger()->verify($person));
            $user = $s->sql->one('users', ['user_id' => $person->ownerUserId->value]);
            self::assertSame($row['given_name'], $user['first_name']);
            self::assertSame($row['family_name'], $user['last_name']);
            self::assertFalse(new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql)
                ->isSuper($person->ownerUserId->value));
        }
    }

    public static function replyLoss(): array
    {
        return ['before terminal commit' => [false], 'after terminal commit' => [true]];
    }

    #[DataProvider('replyLoss')]
    public function testPeopleCompletionAndOriginalRetrySurviveProcessLossWithoutDuplicateAccountsOrNotices(
        bool $committed,
    ): void {
        $r = $this->fixture;
        $s = $r->sis;
        $review = $this->queued('people', 2);
        $before = $this->stableFacts();
        $worker = $r->start('import_run', [], $committed ? null : 'import.completed', $committed);
        $r->send($worker, 'go');
        $r->await($worker, $committed ? 'committed' : 'staged');
        if (!$committed) {
            self::assertSame($before, $this->stableFacts());
            self::assertSame(0, $this->job($review)['processed']);
        }
        $r->kill($worker);
        if (!$committed) {
            self::assertSame($before, $this->stableFacts());
            $this->expireLease();
            self::assertSame('completed', $this->imports->worker()->runOne());
        }
        $this->verifyPeople(2);
        self::assertSame('completed', $this->job($review)['state']);
        self::assertSame(2, $this->job($review)['processed']);
        self::assertCount(1, $s->sql->rows('sis_person_imports'));
        self::assertSame([], $s->sql->rows('sis_import_artifacts'));
        $notices = $this->imports->queue()->notices()['items'];
        self::assertCount(1, $notices);
        $this->imports->queue()->deleteNotice(new EntityId($notices[0]['id']));
        $s->clock->time = $s->clock->now()->modify('+2 days');
        $later = $r->facts();
        self::assertSame($this->job($review), $this->imports->queue()->submit($review['review_token']));
        self::assertSame('idle', $this->imports->worker()->runOne());
        self::assertSame($later, $r->facts());
        self::assertSame([], $s->sql->rows('sis_import_notices'));
    }

    public function testInterruptedSecondPeopleBatchPreservesTheFirstAndResumesFromItsCommittedCursor(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $review = $this->queued('people', 21);
        self::assertSame('processed', $this->imports->worker()->runOne());
        self::assertSame(10, $this->job($review)['processed']);
        $before = $this->stableFacts();
        $worker = $r->start('import_run', [], 'person_import.completed');
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $this->stableFacts());
        $r->kill($worker);
        self::assertSame($before, $this->stableFacts());
        self::assertSame(10, $this->job($review)['processed']);
        $this->verifyPeople(10);
        $this->expireLease();
        self::assertSame('processed', $this->imports->worker()->runOne());
        self::assertSame('completed', $this->imports->worker()->runOne());
        $this->verifyPeople(21);
        self::assertSame(21, $this->job($review)['imported']);
        self::assertCount(3, $s->sql->rows('sis_person_imports'));
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        self::assertSame([], $s->sql->rows('sis_import_artifacts'));
    }

    public function testLostCatalogBatchReplyResumesWithoutReapplyingOneHundredDrafts(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $review = $this->queued('course', 101);
        $worker = $r->start('import_run', [], loseReply: true);
        $r->send($worker, 'go');
        $r->await($worker, 'committed');
        $r->kill($worker);
        self::assertSame(100, $this->job($review)['processed']);
        self::assertCount(100, $s->sql->rows('sis_courses'));
        self::assertCount(1, $s->sql->rows('sis_catalog_operations'));
        self::assertCount(1, $s->sql->rows('sis_import_artifacts'));
        self::assertSame([], $s->sql->rows('sis_import_notices'));
        self::assertSame('completed', $this->imports->worker()->runOne());
        self::assertSame(101, $this->job($review)['imported']);
        self::assertCount(101, $s->sql->rows('sis_courses'));
        self::assertCount(101, $s->sql->rows('sis_import_mappings'));
        self::assertCount(2, $s->sql->rows('sis_catalog_operations'));
        self::assertSame([], $s->sql->rows('sis_courses', ['active' => 1]));
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        self::assertSame([], $s->sql->rows('sis_import_artifacts'));
        $later = $r->facts();
        self::assertSame('idle', $this->imports->worker()->runOne());
        self::assertSame($later, $r->facts());
    }

    public static function claimOutcomes(): array
    {
        return ['claim committed' => [false], 'claim interrupted' => [true]];
    }

    #[DataProvider('claimOutcomes')]
    public function testCompetingClaimsHaveOneReferenceImportOutcomeAfterCommitOrProcessLoss(bool $kill): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $review = $this->queued('subject', 1);
        $first = $r->start('import_run', [], 'import.claim_staged');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('import_run', []);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($kill) {
            $r->kill($first);
        } else {
            $r->send($first, 'continue');
            self::assertSame('completed', $r->result($first)['result']['status']);
        }
        $result = $r->result($second);
        self::assertSame('accepted', $result['state']);
        self::assertSame($kill ? 'completed' : 'idle', $result['result']['status']);
        self::assertSame('completed', $this->job($review)['state']);
        self::assertCount(1, $s->sql->rows('sis_subjects'));
        self::assertCount(1, $s->sql->rows('sis_subject_import_mappings'));
        self::assertCount(1, $s->sql->rows('sis_catalog_operations'));
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        self::assertSame([], $s->sql->rows('sis_import_artifacts'));
    }

    #[DataProvider('claimOutcomes')]
    public function testCompetingQueueConfirmationsRetainOneJobAndNeverImportInTheRequest(bool $kill): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $review = $this->imports->review('people', 1);
        $command = ['token' => $review['review_token']];
        $first = $r->start('import_submit', $command, 'import.queued');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('import_submit', $command);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($kill) {
            $r->kill($first);
        } else {
            $r->send($first, 'continue');
            $firstResult = $r->result($first);
            self::assertSame('accepted', $firstResult['state']);
        }
        $secondResult = $r->result($second);
        self::assertSame('accepted', $secondResult['state']);
        self::assertSame('queued', $secondResult['result']['state']);
        if (!$kill) {
            self::assertSame($firstResult['result'], $secondResult['result']);
        }
        self::assertCount(1, $s->sql->rows('sis_import_jobs'));
        self::assertSame([], $s->sql->rows('sis_persons'));
        self::assertSame([], $s->sql->rows('sis_person_imports'));
        self::assertSame([], $s->sql->rows('sis_import_notices'));
        self::assertSame('completed', $this->imports->worker()->runOne());
        $this->verifyPeople(1);
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
    }
    private function domainFacts(): array
    {
        return array_diff_key(
            $this->stableFacts(),
            array_flip(['sis_import_artifacts', 'sis_import_notices', 'sis_audit'])
        );
    }

    public function testRevokedPersonDutyStopsLaterBatchesWithoutChangingPreviouslyAcceptedPeople(): void
    {
        $s = $this->fixture->sis;
        $review = $this->queued('people', 11);
        self::assertSame('processed', $this->imports->worker()->runOne());
        $permission = $s->sql->one('sis_access_permissions', ['name' => 'sis:person:manage']);
        new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql)->assign(
            \Domain\Access\Enum\GrantKind::UserPermission,
            $s->access->actor,
            new EntityId($permission['id']),
            false
        );
        $before = $this->domainFacts();
        self::assertSame('needs_attention', $this->imports->worker()->runOne());
        self::assertSame($before, $this->domainFacts());
        self::assertSame(10, $this->job($review)['processed']);
        self::assertSame('permission', $this->job($review)['error_code']);
        $this->verifyPeople(10);
        self::assertCount(1, $s->sql->rows('sis_person_imports'));
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        self::assertSame([], $s->sql->rows('sis_import_artifacts'));
    }

    public function testChangedRemainingEmailStopsTheJobWithoutAutomaticIdentityReconciliation(): void
    {
        $s = $this->fixture->sis;
        $review = $this->queued('people', 11);
        self::assertSame('processed', $this->imports->worker()->runOne());
        $existing = $s->registrationService()->staff(
            EntityId::generate(),
            'External',
            'Intake',
            'recovery10@example.invalid'
        );
        $before = $this->domainFacts();
        self::assertSame('needs_attention', $this->imports->worker()->runOne());
        self::assertSame($before, $this->domainFacts());
        self::assertSame(10, $this->job($review)['processed']);
        self::assertSame('conflict', $this->job($review)['error_code']);
        $this->verifyPeople(11);
        self::assertSame('External', $s->admissions->person($existing->id)->givenName);
        self::assertCount(1, $s->sql->rows('sis_person_imports'));
        self::assertCount(1, $s->sql->rows('sis_import_notices'));
        self::assertSame([], $s->sql->rows('sis_import_artifacts'));
    }
}
