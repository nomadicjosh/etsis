<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/SisFixture.php";
use Domain\Shared\ValueObject\EntityId;
use Domain\User\LoginSession;
use Domain\User\Service\{SessionRetention, LoginSessionAuthority};
use Domain\User\ValueObject\VerifiedLoginCookie;
use Infrastructure\Security\{
    SqlSessionRetentionRepository,
    SqlLoginSessionRepository,
    SqlLoginAccountStateReader,
};
use PHPUnit\Framework\TestCase;
final class SessionRetentionTest extends TestCase
{
    private SisFixture $f;
    private SessionRetention $service;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->service = new SessionRetention(
            new SqlSessionRetentionRepository($this->f->sql),
            $this->f->transactions,
            $this->f->clock,
        );
    }
    private function session(int $expiresAgo, bool $revoked = false): EntityId
    {
        $now = $this->f->clock->now()->getTimestamp();
        $id = EntityId::generate();
        new SqlLoginSessionRepository($this->f->sql)->add(
            new LoginSession(
                $id,
                $this->f->access->actor,
                hash("sha256", $id->value),
                hash("sha256", "fictional-revision"),
                $now - $expiresAgo,
                $now - max(86400 * 4, $expiresAgo + 1),
                $revoked ? $now - 60 : null,
            ),
        );
        return $id;
    }
    private function launch(EntityId $session, int $expiresAgo, bool $consumed = true): EntityId
    {
        $id = EntityId::generate();
        $this->f->sql->insert("sis_moodle_launches", [
            "id" => $id->value,
            "ticket_hash" => hash("sha256", $id->value),
            "session_id" => $session->value,
            "user_id" => $this->f->access->actor->value,
            "person_id" => EntityId::generate()->value,
            "binding_id" => EntityId::generate()->value,
            "link_id" => EntityId::generate()->value,
            "moodle_user_id" => "123",
            "ticket_expires_at" => gmdate("Y-m-d\TH:i:s\Z", $this->f->clock->now()->getTimestamp() - 60),
            "session_expires_at" => gmdate(
                "Y-m-d\TH:i:s\Z",
                $this->f->clock->now()->getTimestamp() - $expiresAgo,
            ),
            "consumed" => $consumed ? 1 : 0,
        ]);
        return $id;
    }
    public function testPreviewPredictsChildThenParentCleanupWithoutWritingAnyRecords(): void
    {
        $expired = $this->session(86401, true);
        $this->launch($expired, 86401);
        $this->launch($expired, 86401, false);
        $this->session(-3600);
        $before = $this->f->sql->rows("sis_login_sessions");
        $launches = $this->f->sql->rows("sis_moodle_launches");
        $history = $this->f->sql->rows("sis_history_events");
        $preview = $this->service->run();
        self::assertFalse($preview->applied);
        self::assertSame(2, $preview->launches);
        self::assertSame(1, $preview->sessions);
        self::assertSame($before, $this->f->sql->rows("sis_login_sessions"));
        self::assertSame($launches, $this->f->sql->rows("sis_moodle_launches"));
        $applied = $this->service->run(apply: true);
        self::assertTrue($applied->applied);
        self::assertSame([$preview->launches, $preview->sessions], [$applied->launches, $applied->sessions]);
        self::assertCount(1, $this->f->sql->rows("sis_login_sessions"));
        self::assertSame([], $this->f->sql->rows("sis_moodle_launches"));
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        self::assertSame(0, $this->service->run(apply: true)->sessions);
    }
    public function testActiveSignInsAndUnexpiredRevocationsAreRetained(): void
    {
        $this->session(-3600, false);
        $this->session(-3600, true);
        $grace = $this->session(86400, true);
        $recent = $this->session(3600, true);
        // Expired one-use credentials are not grounds to delete a still-valid grant/reference.
        $this->launch($recent, 3600);
        $this->launch($grace, 86400);
        $result = $this->service->run(apply: true);
        self::assertSame([0, 0], [$result->launches, $result->sessions]);
        self::assertCount(4, $this->f->sql->rows("sis_login_sessions"));
        self::assertCount(2, $this->f->sql->rows("sis_moodle_launches"));
    }
    public function testBatchLimitsBothExaminedTablesAndMakesProgressAcrossRepeatedRuns(): void
    {
        $parent = $this->session(90000);
        for ($i = 0; $i < 3; $i++) {
            $this->launch($parent, 90000);
        }
        $preview = $this->service->run(limit: 1);
        self::assertSame([1, 0, 1], [$preview->launches, $preview->sessions, $preview->referenced]);
        $first = $this->service->run(apply: true, limit: 1);
        self::assertTrue($first->batchFull);
        self::assertSame([1, 0, 1], [$first->launches, $first->sessions, $first->referenced]);
        self::assertCount(2, $this->f->sql->rows("sis_moodle_launches"));
        $this->service->run(apply: true, limit: 1);
        $third = $this->service->run(apply: true, limit: 1);
        self::assertSame([1, 1], [$third->launches, $third->sessions]);
    }
    public function testFutureOrInvalidLaunchReferenceProtectsItsExpiredParent(): void
    {
        $parent = $this->session(90000);
        $future = $this->launch($parent, -3600);
        $invalid = $this->launch($parent, 90000);
        $this->f->sql->update(
            "sis_moodle_launches",
            ["session_expires_at" => "0000-invalid"],
            ["id" => $invalid->value],
        );
        $result = $this->service->run(apply: true);
        self::assertSame(
            [0, 0, 1, 1],
            [$result->launches, $result->sessions, $result->referenced, $result->invalid],
        );
        self::assertSame(
            $parent->value,
            $this->f->sql->one("sis_moodle_launches", ["id" => $future->value])["session_id"],
        );
        self::assertCount(1, $this->f->sql->rows("sis_login_sessions"));
    }
    public function testInvalidStoredExpiryOrIdIsNeverDeleted(): void
    {
        $badDate = $this->session(90000);
        $badId = $this->session(90000);
        $this->f->sql->update(
            "sis_login_sessions",
            ["expires_at" => "2020-02-31T00:00:00Z"],
            ["id" => $badDate->value],
        );
        $this->f->sql->update("sis_login_sessions", ["id" => "invalid-reference"], ["id" => $badId->value]);
        $result = $this->service->run(apply: true);
        self::assertSame(2, $result->invalid);
        self::assertSame(0, $result->sessions);
        self::assertCount(2, $this->f->sql->rows("sis_login_sessions"));
    }
    public function testChangedExpiryBetweenSelectionAndDeletionIsRetained(): void
    {
        $parent = $this->session(90000);
        $a = $this->launch($parent, 90002);
        $b = $this->launch($parent, 90001);
        $future = gmdate("Y-m-d\TH:i:s\Z", $this->f->clock->now()->getTimestamp() + 3600);
        // An independent change between the two conditional deletes, without parallel CPU-heavy workers.
        $pdo = $this->f->connection->pdo;
        $pdo->exec(
            "CREATE TRIGGER extend_next_launch AFTER DELETE ON sis_moodle_launches WHEN OLD.id = " .
                $pdo->quote($a->value) .
                " BEGIN UPDATE sis_moodle_launches SET session_expires_at = " .
                $pdo->quote($future) .
                " WHERE id = " .
                $pdo->quote($b->value) .
                "; END",
        );
        $result = $this->service->run(apply: true);
        self::assertSame(
            [1, 0, 1, 1],
            [$result->launches, $result->sessions, $result->referenced, $result->changed],
        );
        self::assertSame(
            $future,
            $this->f->sql->one("sis_moodle_launches", ["id" => $b->value])["session_expires_at"],
        );
    }
    public function testFailureRollsBackChildAndParentDeletesTogether(): void
    {
        $parent = $this->session(90000);
        $this->launch($parent, 90000);
        $this->f->connection->pdo->exec(
            "CREATE TRIGGER reject_parent BEFORE DELETE ON sis_login_sessions BEGIN SELECT RAISE(ABORT, 'fictional retention failure'); END",
        );
        try {
            $this->service->run(apply: true);
            self::fail();
        } catch (PDOException) {
        }
        self::assertCount(1, $this->f->sql->rows("sis_login_sessions"));
        self::assertCount(1, $this->f->sql->rows("sis_moodle_launches"));
    }
    public function testDeletedReferencesCannotResurrectAnExpiredCookie(): void
    {
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN token VARCHAR(191)");
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(191)");
        $this->f->sql->update(
            "users",
            ["token" => "fictional-token", "password" => "fictional-hash"],
            ["user_id" => $this->f->access->actor->value],
        );
        $authority = new LoginSessionAuthority(
            new SqlLoginSessionRepository($this->f->sql),
            new SqlLoginAccountStateReader($this->f->sql),
            $this->f->access,
            $this->f->transactions,
            $this->f->clock,
        );
        $proof = new VerifiedLoginCookie(
            hash("sha256", "fictional-cookie"),
            hash("sha256", "fictional-token"),
            $this->f->clock->now()->getTimestamp() + 60,
        );
        $authority->current($proof);
        $authority->revokeCurrent($proof);
        $this->f->clock->time = $this->f->clock->time->modify("+25 hours");
        self::assertSame(1, $this->service->run(apply: true)->sessions);
        self::assertFalse($authority->permits($proof));
    }
    public function testInvalidBoundsDoNotReachStorage(): void
    {
        $this->f->connection->pdo->exec("DROP TABLE sis_moodle_launches");
        foreach ([[0, 24], [501, 24], [1, 0], [1, 8761]] as [$limit, $hours]) {
            try {
                $this->service->run(apply: true, limit: $limit, graceHours: $hours);
                self::fail();
            } catch (InvalidArgumentException) {
                self::assertFalse($this->f->connection->pdo->inTransaction());
            }
        }
    }
    public function testGraceUsesElapsedHoursAcrossDaylightSavingChange(): void
    {
        $this->f->clock->time = new DateTimeImmutable("2026-11-01T02:30:00", new DateTimeZone("America/Los_Angeles"));
        $this->session(86401);
        $result = $this->service->run();
        self::assertSame("2026-10-31T10:30:00Z", $result->cutoff->format("Y-m-d\TH:i:s\Z"));
        self::assertSame('UTC', $result->cutoff->getTimezone()->getName());
        self::assertSame(1, $result->sessions);
    }
}
