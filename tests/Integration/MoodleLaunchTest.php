<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/SisFixture.php";
use Domain\Lms\Service\{MoodleLaunchService, MoodleLaunchPolicy};
use Domain\Lms\ValueObject\{MoodleBinding, MoodlePersonLink, MoodleExternalId, MoodleSiteUrl};
use Domain\Person\Person;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\LoginSessionAuthority;
use Domain\User\ValueObject\VerifiedLoginCookie;
use Infrastructure\Lms\{
    SqlMoodleLaunchRepository,
    SqlMoodleBindingRepository,
    SqlMoodlePersonLinkRepository,
    SignedMoodleSessionChannel,
};
use Infrastructure\Security\{SqlLoginSessionRepository, SqlLoginAccountStateReader};
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Qubus\Config\ConfigContainer;
use PHPUnit\Framework\TestCase;
final class MoodleLaunchTest extends TestCase
{
    private SisFixture $f;
    private MoodleLaunchService $service;
    private LoginSessionAuthority $sessions;
    private VerifiedLoginCookie $proof;
    private MoodleLaunchPolicy $policy;
    private EntityId $person;
    private SqlMoodleLaunchRepository $launches;
    private string $instance;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN token VARCHAR(191)");
        $this->f->connection->pdo->exec("ALTER TABLE users ADD COLUMN password VARCHAR(191)");
        $this->f->sql->update(
            "users",
            ["token" => "fictional-token", "password" => "fictional-hash"],
            ["user_id" => $this->f->access->actor->value],
        );
        $this->proof = new VerifiedLoginCookie(
            hash("sha256", "fictional-cookie"),
            hash("sha256", "fictional-token"),
            $this->f->clock->now()->getTimestamp() + 3600,
        );
        $this->sessions = new LoginSessionAuthority(
            new SqlLoginSessionRepository($this->f->sql),
            new SqlLoginAccountStateReader($this->f->sql),
            $this->f->access,
            $this->f->transactions,
            $this->f->clock,
        );
        $this->policy = new class implements MoodleLaunchPolicy {
            public bool $on = true;
            public function enabled(): bool
            {
                return $this->on;
            }
            public function site(): MoodleSiteUrl
            {
                return new MoodleSiteUrl("https://learning.example.test");
            }
        };
        $this->instance = hash("sha256", str_repeat("a", 32));
        $bindings = new SqlMoodleBindingRepository($this->f->sql);
        $binding = new MoodleBinding(
            EntityId::generate(),
            hash("sha256", $this->policy->site()->value),
            $this->instance,
            $this->f->access->actor,
            "2026-08-10T17:00:00Z",
        );
        $bindings->add($binding, "fictional-encrypted-review");
        $person = new Person(
            EntityId::generate(),
            $this->f->access->actor,
            "Alex",
            "Example",
            new InstitutionalId("TEST-01"),
        );
        $this->person = $person->id;
        $this->f->admissions->addPerson($person);
        $links = new SqlMoodlePersonLinkRepository($this->f->sql);
        $links->add(
            new MoodlePersonLink(
                EntityId::generate(),
                $binding->id,
                $person->id,
                new MoodleExternalId("123"),
                $this->f->access->actor,
                "2026-08-10T17:00:00Z",
            ),
            "fictional-encrypted-review",
        );
        $this->launches = new SqlMoodleLaunchRepository($this->f->sql);
        $this->service = new MoodleLaunchService(
            $this->launches,
            $bindings,
            $links,
            $this->policy,
            $this->sessions,
            $this->f->access,
            $this->f->transactions,
            $this->f->clock,
        );
    }
    private function grant(): \Domain\Lms\ValueObject\MoodleLaunch
    {
        return $this->service->exchange($this->service->begin($this->proof)->ticket, $this->instance);
    }
    public function testOwnedLaunchOneUseAndOriginalDeadlineWithNoHistoricalWrites(): void
    {
        $events = $this->f->sql->rows("sis_history_events");
        self::assertTrue($this->service->available());
        self::assertSame([], $this->f->sql->rows("sis_login_sessions"));
        $handoff = $this->service->begin($this->proof);
        $row = $this->f->sql->rows("sis_moodle_launches")[0];
        self::assertSame(hash("sha256", $handoff->ticket), $row["ticket_hash"]);
        self::assertStringNotContainsString($handoff->ticket, json_encode($row));
        self::assertStringNotContainsString($handoff->ticket, json_encode($handoff));
        self::assertSame(
            $this->f->clock->now()->getTimestamp() + 60,
            $this->launches->forTicket($row["ticket_hash"])->ticketExpiresAt,
        );
        $grant = $this->service->exchange($handoff->ticket, $this->instance);
        self::assertSame($this->proof->expiresAt, $grant->sessionExpiresAt);
        $this->f->clock->time = $this->f->clock->time->modify("+2 minutes");
        self::assertNotNull($this->service->check($grant->id, "123", $this->instance));
        self::assertNull($this->service->check($grant->id, "456", $this->instance));
        self::assertNull($this->service->check($grant->id, "123", str_repeat("b", 64)));
        self::assertSame($events, $this->f->sql->rows("sis_history_events"));
        $this->expectException(AccessDeniedException::class);
        $this->service->exchange($handoff->ticket, $this->instance);
    }
    public function testAnotherBrowserOwnerCannotMintFromTheFirstOwnersCookie(): void
    {
        $this->f->access->actor = $this->f->user();
        self::assertFalse($this->service->available());
        $this->expectException(AccessDeniedException::class);
        $this->service->begin($this->proof);
    }
    public function testExpiredUnusedTicketIsRejected(): void
    {
        $handoff = $this->service->begin($this->proof);
        $this->f->clock->time = $this->f->clock->time->modify("+60 seconds");
        $this->expectException(AccessDeniedException::class);
        $this->service->exchange($handoff->ticket, $this->instance);
    }
    public function testRevocationEndsGrantButOtherSignInsRemainSeparate(): void
    {
        $grant = $this->grant();
        $this->sessions->revokeCurrent($this->proof);
        self::assertNull($this->service->check($grant->id, "123", $this->instance));
    }
    public function testPasswordChangeEndsGrant(): void
    {
        $grant = $this->grant();
        $this->f->sql->update(
            "users",
            ["password" => "new-fictional-hash"],
            ["user_id" => $grant->user->value],
        );
        self::assertNull($this->service->check($grant->id, "123", $this->instance));
    }
    public function testOwnerOrLinkReassignmentEndsGrant(): void
    {
        $grant = $this->grant();
        $this->f->sql->update(
            "sis_persons",
            ["owner_user_id" => $this->f->user()->value],
            ["id" => $this->person->value],
        );
        self::assertNull($this->service->check($grant->id, "123", $this->instance));
    }
    public function testLinkReplacementCannotInheritAnOldGrant(): void
    {
        $grant = $this->grant();
        $this->f->sql->update(
            "sis_moodle_person_links",
            ["moodle_user_id" => "456"],
            ["person_id" => $this->person->value],
        );
        self::assertNull($this->service->check($grant->id, "123", $this->instance));
    }
    public function testOriginalExpiryIsAbsolute(): void
    {
        $grant = $this->grant();
        $this->f->clock->time = new DateTimeImmutable("@" . $this->proof->expiresAt);
        self::assertNull($this->service->check($grant->id, "123", $this->instance));
    }
    private function channel(): SignedMoodleSessionChannel
    {
        $config = \Qubus\Config\Collection::factory([]);
        $config->setConfigKey("moodle", ["launch_secret" => str_repeat("c", 64)]);
        return new SignedMoodleSessionChannel($this->service, $this->policy, $config, $this->f->clock);
    }
    private function request(array $data, int $offset = 0): ServerRequest
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $time = (string) ($this->f->clock->now()->getTimestamp() + $offset);
        $nonce = str_repeat("d", 64);
        $stream = new Stream("php://temp", "w+");
        $stream->write($body);
        $stream->rewind();
        return new ServerRequest([], [], "/sis/moodle/session-channel/", "POST", $stream, [
            "Content-Type" => "application/json",
            "X-Etsis-Time" => $time,
            "X-Etsis-Nonce" => $nonce,
            "X-Etsis-Signature" => hash_hmac(
                "sha256",
                "etsis-moodle/v1\nrequest\n$time\n$nonce\n$body",
                hex2bin(str_repeat("c", 64)),
            ),
        ]);
    }
    public function testSignedExchangeBindsResponseToNonceAndTimestamps(): void
    {
        $ticket = $this->service->begin($this->proof)->ticket;
        $request = $this->request([
            "op" => "exchange",
            "ticket" => $ticket,
            "instance_hash" => $this->instance,
        ]);
        $reply = $this->channel()->handle($request);
        self::assertSame(200, $reply->getStatusCode());
        $body = (string) $reply->getBody();
        self::assertTrue(json_decode($body, true)["active"]);
        self::assertStringNotContainsString($ticket, $body);
        self::assertSame(
            hash_hmac(
                "sha256",
                "etsis-moodle/v1\nresponse\n" .
                    $request->getHeaderLine("X-Etsis-Time") .
                    "\n" .
                    $request->getHeaderLine("X-Etsis-Nonce") .
                    "\n" .
                    $body,
                hex2bin(str_repeat("c", 64)),
            ),
            $reply->getHeaderLine("X-Etsis-Signature"),
        );
        self::assertStringContainsString("no-store", $reply->getHeaderLine("Cache-Control"));
    }
    public function testBadSignaturesAndStaleRequestsCannotConsumeTicket(): void
    {
        $ticket = $this->service->begin($this->proof)->ticket;
        $data = ["op" => "exchange", "ticket" => $ticket, "instance_hash" => $this->instance];
        self::assertSame(
            403,
            $this->channel()
                ->handle($this->request($data)->withHeader("X-Etsis-Signature", str_repeat("0", 64)))
                ->getStatusCode(),
        );
        self::assertSame(403, $this->channel()->handle($this->request($data, -31))->getStatusCode());
        self::assertSame(
            403,
            $this->channel()
                ->handle($this->request($data)->withMethod("GET"))
                ->getStatusCode(),
        );
        self::assertFalse($this->launches->forTicket(hash("sha256", $ticket))->consumed);
        self::assertNotNull($this->service->exchange($ticket, $this->instance));
    }
    public function testSignedWrongInstanceAndExtraIdentityInputsAreRefused(): void
    {
        $ticket = $this->service->begin($this->proof)->ticket;
        $data = ["op" => "exchange", "ticket" => $ticket, "instance_hash" => str_repeat("e", 64)];
        self::assertSame(
            ["active" => false],
            json_decode((string) $this->channel()->handle($this->request($data))->getBody(), true),
        );
        $data["instance_hash"] = $this->instance;
        $data["moodle_user_id"] = "456";
        self::assertSame(
            ["active" => false],
            json_decode((string) $this->channel()->handle($this->request($data))->getBody(), true),
        );
        self::assertFalse($this->launches->forTicket(hash("sha256", $ticket))->consumed);
    }
}
