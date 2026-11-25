<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\GraduateRegistrationPolicy;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\GraduateRegistrationFixture;

final class GraduateHistoryVerifierTest extends TestCase
{
    private function fixture(): GraduateRegistrationFixture
    {
        require_once dirname(__DIR__) . "/Support/GraduateRegistrationFixture.php";
        return new GraduateRegistrationFixture();
    }

    public function testEmptyPolicyAndPursuitAreHonestReadOnlyObservations(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new \SisFixture();
        $student = $s->student();
        $pursuit = $s->workPursuits()->activePursuit($student->id);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec("PRAGMA query_only = ON");
        self::assertSame("observed_empty", $s->graduateVerifier()->policy()->status);
        self::assertSame("observed_empty", $s->graduateVerifier()->approvals($pursuit)->status);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testPublishedAndRevokedDecisionsAuthenticateWithoutCurrentGpaRecalculation(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $f->decide("advisor_revoke");
        $f->policy(gpaMinimum: 4000);
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->connection->pdo->exec("PRAGMA query_only = ON");
        $policy = $s->graduateVerifier()->policy();
        self::assertSame("verified", $policy->status);
        self::assertSame(2, $policy->records);
        $result = $s->graduateVerifier()->approvals($f->pursuit);
        self::assertSame("verified", $result->status);
        self::assertSame(3, $result->records);
        $privateValues = [
            "GPA",
            "reason",
            "actor_id",
            "minimum_gpa",
            $f->advisorUser->value,
            $f->target->section->id->value,
        ];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString(
                $private,
                json_encode($result->describe(), JSON_THROW_ON_ERROR),
            );
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testDamagedCounterAndOfferingCacheFailWithoutSuccessfulRecordCounts(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->decide("advisor_approve");
        $s->sql->update("sis_graduate_course_approvals", ["version" => 2], ["id" => $f->pursuit->value]);
        $bad = $s->graduateVerifier()->approvals($f->pursuit);
        self::assertSame("unverified", $bad->status);
        self::assertSame(["graduate_history"], $bad->issues);
        self::assertNull($bad->records);
        $s->sql->update("sis_graduate_course_approvals", ["version" => 1], ["id" => $f->pursuit->value]);
        $s->sql->update(
            "sis_section_versions",
            ["capacity" => 999],
            ["section_id" => $f->target->section->id->value],
        );
        $bad = $s->graduateVerifier()->approvals($f->pursuit);
        self::assertSame("unverified", $bad->status);
        self::assertSame(["retained_reference"], $bad->issues);
        self::assertNull($bad->records);
    }

    public function testMissingPolicyCounterNeverLooksLikeAnUnpublishedDefault(): void
    {
        $f = $this->fixture();
        $f->sis->sql->execute("DELETE FROM sis_graduate_registration_policy_state");
        $result = $f->sis->graduateVerifier()->policy();
        self::assertSame("unverified", $result->status);
        self::assertNull($result->records);
    }

    public function testSelectedHistoryAndItsReferencesShareOneReplayAllowance(): void
    {
        $f = $this->fixture();
        $f->decide("advisor_approve");
        $f->decide("registrar_override");
        $result = $f->sis->graduateVerifier()->approvals($f->pursuit, 2);
        self::assertSame("limited", $result->status);
        self::assertSame(2, $result->events);
        self::assertNull($result->records);
    }

    public function testEnclosingTransactionsAreRefusedBeforeHistoryReads(): void
    {
        $f = $this->fixture();
        $f->sis->transactions->run(function () use ($f): void {
            $policy = $f->sis->graduateVerifier()->policy();
            $pursuit = $f->sis->graduateVerifier()->approvals($f->pursuit);
            self::assertSame(["unavailable"], $policy->issues);
            self::assertSame(["unavailable"], $pursuit->issues);
            self::assertSame(0, $pursuit->events);
        });
    }

    public function testChangedRoutingDuringReplayIsAnIncompleteObservation(): void
    {
        $f = $this->fixture();
        $f->decide("advisor_approve");
        $s = $f->sis;
        $actual = \EventStoreFixture::history($s->connection->queryBuilder());
        $history = $this->createStub(HistoricalEventStore::class);
        $changed = false;
        $history
            ->method("page")
            ->willReturnCallback(static function ($stream, $cursor, $limit) use ($actual, $s, $f, &$changed) {
                $page = $actual->page($stream, $cursor, $limit);
                if (!$changed) {
                    $changed = true;
                    $s->sql->update(
                        "sis_student_programs",
                        ["status" => "closed"],
                        ["id" => $f->pursuit->value],
                    );
                }
                return $page;
            });
        $result = $s->graduateVerifier($history)->approvals($f->pursuit);
        self::assertSame("changed", $result->status);
        self::assertNull($result->records);
    }
    public function testPolicyTraversesMultiplePagesAndAcceptsAnExactEventLimit(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new \SisFixture();
        $policy = $s->graduatePolicies();
        $rules = \Domain\Registration\ValueObject\GraduateRegistrationRules::DEFAULT;
        for ($version = 0; $version < 21; $version++) {
            $review = $policy->review($rules, $version, "Fictional retained policy publication.");
            $policy->publish(
                new EntityId($review["publication_id"]),
                $review["rules"],
                $review["version"],
                $review["reason"],
                $review["review_token"],
            );
        }
        $s->connection->pdo->exec("PRAGMA query_only = ON");
        self::assertSame("limited", $s->graduateVerifier()->policy(20)->status);
        $exact = $s->graduateVerifier()->policy(21);
        self::assertSame("verified", $exact->status);
        self::assertSame(21, $exact->records);
        self::assertSame(21, $exact->events);
    }

    public function testAReferenceChangedDuringReplayCannotProduceASuccessfulObservation(): void
    {
        $f = $this->fixture();
        $f->decide("advisor_approve");
        $s = $f->sis;
        $actual = \EventStoreFixture::history($s->connection->queryBuilder());
        $history = $this->createStub(HistoricalEventStore::class);
        $changed = false;
        $history
            ->method("page")
            ->willReturnCallback(static function ($stream, $cursor, $limit) use ($actual, $s, $f, &$changed) {
                $page = $actual->page($stream, $cursor, $limit);
                if (!$changed && $stream->type === "course_publication") {
                    $changed = true;
                    $s->sql->update(
                        "sis_section_versions",
                        ["capacity" => 77],
                        ["section_id" => $f->target->section->id->value],
                    );
                }
                return $page;
            });
        $result = $s->graduateVerifier($history)->approvals($f->pursuit);
        self::assertSame("changed", $result->status);
        self::assertNull($result->records);
    }

    public function testAStableButCorruptCourseCacheIsComparedWithTheOriginalPublication(): void
    {
        $f = $this->fixture();
        $f->decide("advisor_approve");
        $f->sis->sql->update(
            "sis_course_versions",
            ["title" => "Fictional corrupted retained title"],
            ["course_id" => $f->target->section->courseId->value],
        );
        $result = $f->sis->graduateVerifier()->approvals($f->pursuit);
        self::assertSame("unverified", $result->status);
        self::assertSame(["retained_reference"], $result->issues);
        self::assertNull($result->records);
    }
}
