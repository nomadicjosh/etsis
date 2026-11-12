<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/SisFixture.php";
use Domain\User\Service\LoginSessionAuthority;
use Domain\User\ValueObject\VerifiedLoginCookie;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Infrastructure\Security\{SqlLoginAccountStateReader, SqlLoginSessionRepository};
use PHPUnit\Framework\TestCase;
final class LoginSessionAuthorityTest extends TestCase
{
    private SisFixture $f;
    private LoginSessionAuthority $service;
    private SqlLoginSessionRepository $sessions;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN token VARCHAR(191)");
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(191)");
        $this->f->sql->update(
            "users",
            ["token" => "fictional-token", "password" => "fictional-password-hash"],
            ["user_id" => $this->f->access->actor->value],
        );
        $this->sessions = new SqlLoginSessionRepository($this->f->sql);
        $this->service = new LoginSessionAuthority(
            $this->sessions,
            new SqlLoginAccountStateReader($this->f->sql),
            $this->f->access,
            $this->f->transactions,
            $this->f->clock,
        );
    }
    private function proof(string $browser = "one", int $ttl = 3600): VerifiedLoginCookie
    {
        return new VerifiedLoginCookie(
            hash("sha256", "fictional-cookie-" . $browser),
            hash("sha256", "fictional-token"),
            $this->f->clock->now()->getTimestamp() + $ttl,
        );
    }
    public function testRegistrationRetainsExactExpiryAndOnlyPrivateDigests(): void
    {
        $history = $this->f->sql->rows("sis_history_events");
        $proof = $this->proof();
        self::assertTrue($this->service->permits($proof));
        self::assertSame([], $this->f->sql->rows("sis_login_sessions"));
        $session = $this->service->current($proof);
        self::assertSame($proof->expiresAt, $session->expiresAt);
        $this->f->clock->time = $this->f->clock->time->modify("+10 minutes");
        self::assertSame($session->id->value, $this->service->current($proof)->id->value);
        self::assertTrue($this->service->activeReference($session->id, $this->f->access->actor));
        self::assertFalse($this->service->activeReference($session->id, EntityId::generate()));
        $rows = $this->f->sql->rows("sis_login_sessions");
        self::assertCount(1, $rows);
        self::assertSame("2026", substr($rows[0]["expires_at"], 0, 4));
        foreach (["fictional-token", "fictional-password-hash", "fictional-cookie-one"] as $private) {
            self::assertStringNotContainsString($private, json_encode($rows, JSON_THROW_ON_ERROR));
        }
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        self::assertStringNotContainsString($session->id->value, json_encode($session));
    }
    public function testLogoutRevokesOnlyThisBrowserAndKeepsFirstDecision(): void
    {
        $first = $this->proof("one");
        $second = $this->proof("two");
        $s1 = $this->service->current($first);
        $s2 = $this->service->current($second);
        $this->service->revokeCurrent($first);
        $time = $this->sessions->byId($s1->id)->revokedAt;
        $this->f->clock->time = $this->f->clock->time->modify("+10 seconds");
        $this->service->revokeCurrent($first);
        self::assertSame($time, $this->sessions->byId($s1->id)->revokedAt);
        self::assertFalse($this->service->permits($first));
        self::assertTrue($this->service->permits($second));
        self::assertFalse($this->service->activeReference($s1->id, $s1->user));
        self::assertTrue($this->service->activeReference($s2->id, $s2->user));
        $this->expectException(AccessDeniedException::class);
        $this->service->current($first);
    }
    public function testLogoutBeforeRegistrationRetainsDenialTombstone(): void
    {
        $proof = $this->proof();
        $this->service->revokeCurrent($proof);
        self::assertFalse($this->service->permits($proof));
        self::assertCount(1, $this->f->sql->rows("sis_login_sessions"));
        $this->expectException(AccessDeniedException::class);
        $this->service->current($proof);
    }
    public function testExpiryIsNotExtendedByReadingOrReregistering(): void
    {
        $proof = $this->proof();
        $session = $this->service->current($proof);
        $this->f->clock->time = new DateTimeImmutable("@" . $proof->expiresAt);
        self::assertFalse($this->service->permits($proof));
        self::assertFalse($this->service->activeReference($session->id, $session->user));
        self::assertSame($proof->expiresAt, $this->sessions->byId($session->id)->expiresAt);
        $this->expectException(AccessDeniedException::class);
        $this->service->current($proof);
    }
    public function testAnotherActorCannotRegisterOrRevokeTheCookie(): void
    {
        $proof = $this->proof();
        $session = $this->service->current($proof);
        $other = $this->f->user();
        $this->f->access->actor = $other;
        $this->f->sql->update(
            "users",
            ["token" => "different-token", "password" => "different-hash"],
            ["user_id" => $other->value],
        );
        try {
            $this->service->current($proof);
            self::fail();
        } catch (AccessDeniedException) {
        }
        try {
            $this->service->revokeCurrent($proof);
            self::fail();
        } catch (AccessDeniedException) {
        }
        self::assertNull($this->sessions->byId($session->id)->revokedAt);
    }
    public function testPasswordChangeInvalidatesTrackedSessionWithoutCopyingPassword(): void
    {
        $proof = $this->proof();
        $session = $this->service->current($proof);
        $this->f->sql->update("users", ["password" => "changed-hash"], ["user_id" => $session->user->value]);
        self::assertFalse($this->service->permits($proof));
        self::assertFalse($this->service->activeReference($session->id, $session->user));
        $this->expectException(AccessDeniedException::class);
        $this->service->current($proof);
    }
    public function testDeletedOrTokenChangedAccountIsNoLongerActive(): void
    {
        $proof = $this->proof();
        $session = $this->service->current($proof);
        $this->f->sql->update("users", ["token" => "changed-token"], ["user_id" => $session->user->value]);
        self::assertFalse($this->service->activeReference($session->id, $session->user));
        $this->f->sql->execute("DELETE FROM users WHERE user_id = ?", [$session->user->value]);
        self::assertFalse($this->service->activeReference($session->id, $session->user));
        self::assertNotNull($this->sessions->byId($session->id));
    }
    public function testNoProofLogoutDoesNotRegisterOrReadUserState(): void
    {
        $this->service->revokeCurrent(null);
        self::assertSame([], $this->f->sql->rows("sis_login_sessions"));
    }
    public function testCompetingRegistrationAndLogoutNeverReactivateAReference(): void
    {
        foreach ([true, false] as $competingRevocation) {
            $sqlitePath = tempnam(sys_get_temp_dir(), "etsis-login-race-");
            try {
                $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
                    "driver" => "pdo_sqlite",
                    "dsn" => "sqlite:" . $sqlitePath,
                ]);
                $connection->pdo->exec("PRAGMA journal_mode=WAL");
                $f = new SisFixture($connection);
                foreach (["token", "password"] as $column) {
                    $connection->pdo->exec("ALTER TABLE users ADD COLUMN " . $column . " VARCHAR(191)");
                }
                $f->sql->update(
                    "users",
                    ["token" => "fictional-token", "password" => "fictional-hash"],
                    ["user_id" => $f->access->actor->value],
                );
                $second = new \Qubus\Expressive\Connection\Pdo\Sqlite([
                    "driver" => "pdo_sqlite",
                    "dsn" => "sqlite:" . $sqlitePath,
                ]);
                $other = new SqlLoginSessionRepository(
                    new \Infrastructure\Persistence\SqlRecords($second->queryBuilder()),
                );
                $local = new SqlLoginSessionRepository($f->sql);
                $racing = new class ($local, $other, $competingRevocation) implements
                    \Domain\User\Repository\LoginSessionRepository
                {
                    private bool $first = true;
                    public function __construct(
                        private SqlLoginSessionRepository $local,
                        private SqlLoginSessionRepository $other,
                        private bool $revoke,
                    ) {}
                    public function forCookie(string $hash): ?\Domain\User\LoginSession
                    {
                        return $this->local->forCookie($hash);
                    }
                    public function byId(EntityId $id): ?\Domain\User\LoginSession
                    {
                        return $this->local->byId($id);
                    }
                    public function revoke(EntityId $id, int $at): void
                    {
                        $this->local->revoke($id, $at);
                    }
                    public function add(\Domain\User\LoginSession $candidate): void
                    {
                        if ($this->first) {
                            $this->first = false;
                            // Commit through an independent connection after the first scope read absence.
                            $this->other->add(
                                new \Domain\User\LoginSession(
                                    EntityId::generate(),
                                    $candidate->user,
                                    $candidate->cookieHash,
                                    $candidate->accountRevision,
                                    $candidate->expiresAt,
                                    $candidate->observedAt,
                                    $this->revoke ? $candidate->observedAt : null,
                                ),
                            );
                        }
                        $this->local->add($candidate);
                    }
                };
                $authority = new LoginSessionAuthority(
                    $racing,
                    new SqlLoginAccountStateReader($f->sql),
                    $f->access,
                    $f->transactions,
                    $f->clock,
                );
                $proof = new VerifiedLoginCookie(
                    hash("sha256", "fictional-racing-cookie"),
                    hash("sha256", "fictional-token"),
                    $f->clock->now()->getTimestamp() + 3600,
                );
                if ($competingRevocation) {
                    try {
                        $authority->current($proof);
                        self::fail("A concurrent revocation was reactivated.");
                    } catch (AccessDeniedException) {
                    }
                } else {
                    $authority->revokeCurrent($proof);
                }
                self::assertFalse($authority->permits($proof));
                $rows = $f->sql->rows("sis_login_sessions");
                self::assertCount(1, $rows);
                self::assertSame(1, (int) $rows[0]["revoked"]);
            } finally {
                foreach ([$sqlitePath, $sqlitePath . "-wal", $sqlitePath . "-shm"] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        }
    }
    public function testChangedExpiryCannotRebindExistingCookie(): void
    {
        $proof = $this->proof();
        $this->service->current($proof);
        $changed = new VerifiedLoginCookie($proof->cookieHash, $proof->tokenHash, $proof->expiresAt + 30);
        self::assertFalse($this->service->permits($changed));
        $this->expectException(AccessDeniedException::class);
        $this->service->current($changed);
    }
}
