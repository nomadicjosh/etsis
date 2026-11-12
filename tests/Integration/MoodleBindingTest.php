<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleBindingService;
use Domain\Lms\Service\MoodleInstanceReader;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleInstallation;
use Domain\Lms\ValueObject\MoodleInstanceIdentity;
use Domain\Lms\ValueObject\MoodleSiteUrl;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Lms\SqlMoodleBindingRepository;
use PHPUnit\Framework\TestCase;

final class MoodleBindingTest extends TestCase
{
    private SisFixture $f;
    private MoodleInstanceReader $reader;
    private SqlMoodleBindingRepository $bindings;
    private MoodleBindingService $service;

    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->reader = new class implements MoodleInstanceReader {
            public bool $active = true;
            public int $calls = 0;
            public ?Closure $duringCall = null;
            public string $site = "https://moodle.example.invalid/campus";
            public string $identity = "0123456789abcdef0123456789abcdef";

            public function enabled(): bool
            {
                return $this->active;
            }
            public function identify(): MoodleInstallation
            {
                $this->calls++;
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                return new MoodleInstallation(
                    new MoodleSiteUrl($this->site),
                    new MoodleInstanceIdentity($this->identity),
                );
            }
        };
        $this->bindings = new SqlMoodleBindingRepository($this->f->sql);
        $this->service = $this->service();
    }

    private function service(?AuditTrail $audit = null): MoodleBindingService
    {
        return new MoodleBindingService(
            $this->reader,
            $this->bindings,
            $this->f->access,
            $this->f->transactions,
            $audit ?? $this->f->audit,
            $this->f->dropCipher,
            $this->f->clock,
        );
    }

    private function review(): array
    {
        return $this->service->review("Institution-approved teaching platform.");
    }

    public function testStatusAndReviewDoNotCreateBindingOrChangeAcademicHistory(): void
    {
        $before = $this->f->sql->rows("sis_history_events");
        self::assertSame(["binding" => null], $this->service->status());
        self::assertSame(0, $this->reader->calls);
        $this->reader->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $review = $this->review();
        self::assertSame(hash("sha256", $this->reader->identity), $review["instance_fingerprint"]);
        self::assertSame(hash("sha256", $this->reader->site), $review["site_fingerprint"]);
        self::assertNull($this->bindings->current());
        self::assertSame($before, $this->f->sql->rows("sis_history_events"));
        self::assertStringNotContainsString($this->reader->identity, json_encode($review));
        self::assertStringNotContainsString($this->reader->site, json_encode($review));
    }

    public function testConfirmationRetainsEncryptedReasonAndRetriesReturnOriginalApproval(): void
    {
        $this->reader->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $review = $this->review();
        $result = $this->service->confirm($review["review_token"]);
        self::assertSame(2, $this->reader->calls);
        $row = $this->f->sql->rows("sis_moodle_bindings")[0];
        self::assertSame(
            "Institution-approved teaching platform.",
            $this->f->dropCipher->open($row["encrypted_reason"]),
        );
        self::assertStringNotContainsString("Institution-approved", json_encode($row));
        self::assertSame($this->f->access->actor->value, $row["approved_by"]);
        self::assertSame($result, $this->service->status());
        $this->f->clock->time = $this->f->clock->time->modify("+1 day");
        $this->reader->active = false;
        self::assertSame($result, $this->service->confirm($review["review_token"]));
        self::assertSame(2, $this->reader->calls);
        self::assertCount(1, $this->f->audit->forSubject(new EntityId($row["id"])));
        self::assertCount(1, $this->f->sql->rows("sis_moodle_bindings"));
    }

    public function testExpiredUnconfirmedReviewCannotContactMoodleOrSave(): void
    {
        $review = $this->review();
        $this->f->clock->time = $this->f->clock->time->modify("+20 minutes");
        try {
            $this->service->confirm($review["review_token"]);
            self::fail();
        } catch (ValidationException $error) {
            self::assertSame("review_token", $error->errors[0]["field"]);
        }
        self::assertSame(1, $this->reader->calls);
        self::assertNull($this->bindings->current());
    }

    public function testReviewExpiringDuringExternalReadCannotCommit(): void
    {
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
        };
        try {
            $this->service->confirm($review["review_token"]);
            self::fail();
        } catch (ValidationException) {
            self::assertNull($this->bindings->current());
        }
    }

    public function testAnotherActorCannotUseTheReview(): void
    {
        $review = $this->review();
        $this->f->access->actor = $this->f->user();
        $this->expectException(AccessDeniedException::class);
        $this->service->confirm($review["review_token"]);
    }

    public function testUnrelatedPermissionsCannotReadOrApproveBindings(): void
    {
        $review = $this->review();
        $this->f->access->staff = false;
        $this->f->access->grants = ["admin:dashboard", "sis:catalog:manage", "admin:access:manage"];
        foreach (
            [
                fn() => $this->service->status(),
                fn() => $this->review(),
                fn() => $this->service->confirm($review["review_token"]),
                fn() => $this->service->verify(),
            ]
            as $action
        ) {
            try {
                $action();
                self::fail();
            } catch (AccessDeniedException) {
                self::assertNull($this->bindings->current());
            }
        }
        self::assertSame(1, $this->reader->calls);
    }

    public function testPermissionRevocationDuringConfirmationDiscardsTheRead(): void
    {
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->f->access->staff = false;
        };
        try {
            $this->service->confirm($review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertNull($this->bindings->current());
        }
    }

    public function testDisablementPreventsNewApprovalButDoesNotEraseSavedApproval(): void
    {
        $this->reader->active = false;
        try {
            $this->review();
            self::fail();
        } catch (MoodleConnectionUnavailableException) {
            self::assertSame(0, $this->reader->calls);
        }
        $this->reader->active = true;
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->reader->active = false;
        };
        try {
            $this->service->confirm($review["review_token"]);
            self::fail();
        } catch (MoodleConnectionUnavailableException) {
            self::assertNull($this->bindings->current());
        }
    }

    public function testAddressOrIdentityChangesRequireNewReviewAndNeverReplaceExistingApproval(): void
    {
        foreach (["site", "identity"] as $field) {
            $review = $this->review();
            $original = $this->reader->$field;
            $this->reader->$field = $field === "site" ? "https://other.example.invalid" : str_repeat("a", 32);
            try {
                $this->service->confirm($review["review_token"]);
                self::fail();
            } catch (ConcurrentModificationException) {
                self::assertNull($this->bindings->current());
            }
            $this->reader->$field = $original;
        }
        $review = $this->review();
        $saved = $this->service->confirm($review["review_token"]);
        $this->reader->identity = str_repeat("b", 32);
        try {
            $this->service->verify();
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame($saved, $this->service->status());
        }
        try {
            $this->review();
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame($saved, $this->service->status());
        }
    }

    public function testFreshVerificationMatchesSavedFingerprintsAndNeedsNoHistoryReplay(): void
    {
        $review = $this->review();
        $saved = $this->service->confirm($review["review_token"]);
        $events = $this->f->sql->rows("sis_history_events");
        self::assertSame($saved + ["verified" => true], $this->service->verify());
        self::assertSame($events, $this->f->sql->rows("sis_history_events"));
    }

    public function testTamperedCrossPurposeAndMalformedReviewsAreRefused(): void
    {
        $review = $this->review();
        $data = json_decode(
            $this->f->dropCipher->open($review["review_token"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach (
            [
                "fictional-token",
                str_repeat("x", 16385),
                $this->f->dropCipher->seal(json_encode($data + ["unexpected" => "private"])),
                $this->f->dropCipher->seal(json_encode(array_replace($data, ["kind" => "another-review"]))),
                $this->f->dropCipher->seal(
                    json_encode(array_replace($data, ["site_fingerprint" => "invalid"])),
                ),
            ]
            as $token
        ) {
            try {
                $this->service->confirm($token);
                self::fail();
            } catch (ValidationException) {
                self::assertNull($this->bindings->current());
            }
        }
        self::assertSame(1, $this->reader->calls);
    }

    public function testCompetingReviewsAndUniqueScopeCannotCreateTwoBindings(): void
    {
        $first = $this->review();
        $second = $this->review();
        $saved = $this->service->confirm($first["review_token"]);
        try {
            $this->service->confirm($second["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame($saved, $this->service->status());
        }
        $binding = $this->bindings->current();
        try {
            $this->f->transactions->run(
                fn() => $this->bindings->add(
                    new MoodleBinding(
                        EntityId::generate(),
                        $binding->siteFingerprint,
                        $binding->instanceFingerprint,
                        $binding->approvedBy,
                        $binding->approvedAt,
                    ),
                    "fictional-ciphertext",
                ),
            );
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_moodle_bindings"));
        }
    }

    public function testApprovalAuditFailureRollsBackTheBinding(): void
    {
        $audit = new class ($this->f->audit) implements AuditTrail {
            public function __construct(private AuditTrail $delegate) {}
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if ($action === "moodle.installation_approved") {
                    throw new RuntimeException("Fictional audit outage.");
                }
                $this->delegate->record($actor, $action, $subject);
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->delegate->forSubject($subject);
            }
        };
        $review = $this->review();
        try {
            $this->service($audit)->confirm($review["review_token"]);
            self::fail();
        } catch (RuntimeException) {
            self::assertNull($this->bindings->current());
        }
    }

    public function testUnexpectedProviderFailureIsSanitizedAndCannotSave(): void
    {
        $review = $this->review();
        $this->reader->duringCall = static function (): never {
            throw new RuntimeException("fictional-secret upstream response");
        };
        try {
            $this->service->confirm($review["review_token"]);
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString("fictional-secret", $error->getMessage());
            self::assertNull($this->bindings->current());
        }
    }

    public function testMissingReasonAndUnapprovedVerificationDoNotContactMoodle(): void
    {
        foreach ([fn() => $this->service->review("  "), fn() => $this->service->verify()] as $action) {
            try {
                $action();
                self::fail();
            } catch (ValidationException) {
                self::assertSame(0, $this->reader->calls);
            }
        }
    }

    public function testMetadataAuditFailurePreventsExternalRead(): void
    {
        $audit = new class implements AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional metadata audit outage.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $this->service($audit)->review("Fictional institutional setup.");
            self::fail();
        } catch (RuntimeException) {
            self::assertSame(0, $this->reader->calls);
            self::assertNull($this->bindings->current());
        }
    }

    public function testRevocationAfterInsertionRollsBackApprovalAndItsAudit(): void
    {
        $review = $this->review();
        $audit = new class ($this->f) implements AuditTrail {
            public function __construct(private SisFixture $f) {}
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->f->audit->record($actor, $action, $subject);
                if ($action === "moodle.installation_approved") {
                    $this->f->access->staff = false;
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->f->audit->forSubject($subject);
            }
        };
        try {
            $this->service($audit)->confirm($review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertNull($this->bindings->current());
            self::assertSame(
                [],
                $this->f->sql->rows("sis_audit", ["action" => "moodle.installation_approved"]),
            );
        }
    }
}
