<?php

declare(strict_types=1);

namespace Tests\Integration;

use ReusableGroupFixture;
use HiatusFixture;
use TransferAwardFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\DegreeAudit\ValueObject\AcademicWorkView;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\GroupWorkEligibility;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransferInstitutionEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
        require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";
    }

    private function source(ReusableGroupFixture $f, string $code = "EXT"): array
    {
        $row = [
            "id" => EntityId::generate()->value,
            "code" => $code,
            "name" => "Fictional sending college",
        ];
        $f->sis->sql->insert("sis_external_institutions", $row);
        return $row;
    }

    private function definition(ReusableGroupFixture $f, array $source): CourseGroupDefinitionV2
    {
        $d = CourseGroupDefinitionV2::blank("Fictional source-restricted group")->definition;
        $d["min_credits_milli"] = 12000;
        $d["courses"] = $f->groupDefinition["courses"];
        $d["rules"] = $f->groupDefinition["rules"];
        $d["work_eligibility"]["transfer_institutions"] = [$source];
        return new CourseGroupDefinitionV2($d);
    }

    private function snapshot(ReusableGroupFixture $f): array
    {
        return array_map($f->digest(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_course_groups",
            "sis_external_institutions",
            "sis_audit",
        ]);
    }

    public function testDirectoryIsBoundedLiteralMetadataOnlyAndRequiresCatalogAuthority(): void
    {
        $f = new ReusableGroupFixture();
        for ($i = 0; $i < 27; $i++) {
            $this->source($f, "EXT." . $i);
        }
        $literal = $this->source($f, "LIT%_!");
        $first = $f->groups->institutionChoices();
        self::assertCount(25, $first["items"]);
        self::assertNotNull($first["next_after"]);
        $next = $f->groups->institutionChoices(after: new EntityId($first["next_after"]));
        self::assertCount(3, $next["items"]);
        self::assertNull($next["next_after"]);
        self::assertSame([$literal], $f->groups->institutionChoices("%_!")["items"]);
        self::assertSame(["id", "code", "name"], array_keys($first["items"][0]));
        $f->sis->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $f->groups->institutionChoices();
    }

    public function testForgedOrChangedLabelsCannotBecomeANewPublication(): void
    {
        $f = new ReusableGroupFixture();
        $source = $this->source($f);
        $forged = array_replace($source, ["name" => "Unverified label"]);
        $before = $this->snapshot($f);
        try {
            $f->groups->save($f->group, 0, $this->definition($f, $forged));
            self::fail("Forged label accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
        $f->groups->save($f->group, 0, $this->definition($f, $source));
        $review = $f->groups->review($f->group, "Fictional source approval");
        $f->sis->sql->update(
            "sis_external_institutions",
            ["name" => "Renamed college"],
            ["id" => $source["id"]],
        );
        $before = $this->snapshot($f);
        try {
            $f->groups->publish($f->group, $review["review_token"]);
            self::fail("Changed source accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testOriginalReceiptSurvivesRenameAndExpiry(): void
    {
        $f = new ReusableGroupFixture();
        $source = $this->source($f);
        $f->groups->save($f->group, 0, $this->definition($f, $source));
        $review = $f->groups->review($f->group, "Fictional source approval");
        $receipt = $f->groups->publish($f->group, $review["review_token"]);
        $f->sis->sql->update(
            "sis_external_institutions",
            ["name" => "Renamed college"],
            ["id" => $source["id"]],
        );
        $f->sis->clock->time = $f->sis->clock->now()->modify("+1 day");
        $before = $this->snapshot($f);
        self::assertSame($receipt, $f->groups->publish($f->group, $review["review_token"]));
        self::assertSame($before, $this->snapshot($f));
        $events = $f->history->page(
            new \Domain\Shared\EventSourcing\StreamReference(
                new \Domain\DegreeAudit\ValueObject\CourseGroupPublicationId($f->group->value),
                "course_group_publication",
            ),
        )->events;
        self::assertStringContainsString(
            $source["name"],
            json_encode($events[1]->event->payload(), JSON_THROW_ON_ERROR),
        );
    }

    public static function auditChanges(): array
    {
        return [["source"], ["authority"], ["draft"], ["expiry"]];
    }

    #[DataProvider("auditChanges")]
    public function testFinalAuditCannotChangeConfirmedInputsOrAuthority(string $change): void
    {
        $f = new ReusableGroupFixture();
        $source = $this->source($f);
        $f->groups->save($f->group, 0, $this->definition($f, $source));
        $review = $f->groups->review($f->group, "Fictional source approval");
        $before = $this->snapshot($f);
        $audit = new class ($f, $source, $change) implements AuditTrail {
            public function __construct(
                private ReusableGroupFixture $f,
                private array $source,
                private string $change,
            ) {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $s = $this->f->sis;
                if ($this->change === "source") {
                    $s->sql->update(
                        "sis_external_institutions",
                        ["name" => "Changed during audit"],
                        ["id" => $this->source["id"]],
                    );
                } elseif ($this->change === "authority") {
                    $s->access->staff = false;
                } elseif ($this->change === "draft") {
                    $s->sql->update(
                        "sis_course_groups",
                        ["revision" => 99],
                        ["id" => $subject->value],
                    );
                } else {
                    $s->clock->time = $s->clock->now()->modify("+20 minutes");
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $f->groupService(audit: $audit)->publish($f->group, $review["review_token"]);
            self::fail("Audit-time change accepted.");
        } catch (ConcurrentModificationException | AccessDeniedException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public static function provenance(): array
    {
        return [[false], [true]];
    }

    #[DataProvider("provenance")]
    public function testAwardSourceComesOnlyFromRetainedEvidenceAndReadDoesNotChangeCredit(
        bool $legacy,
    ): void {
        $h = new HiatusFixture();
        $t = new TransferAwardFixture($h->sis, $h->cipher, legacy: $legacy);
        $s = $h->sis;
        $source = $t->equivalency->externalInstitutionId->value;
        $before = $s->sql->rows("sis_academic_credits");
        $policy = new GroupWorkEligibility(
            array_replace(GroupWorkEligibility::DEFAULT, [
                "transfer_institutions" => [
                    ["id" => $source, "code" => "EXT", "name" => "Published college"],
                ],
            ]),
        );
        $other = EntityId::generate()->value;
        $s->sql->insert("sis_external_institutions", [
            "id" => $other,
            "code" => "OTHER",
            "name" => "Fictional other college",
        ]);
        $s->sql->update(
            "sis_external_courses",
            ["institution_id" => $other],
            ["id" => $t->equivalency->externalCourseId->value],
        );
        $facts = $s->transferCredits()->auditFacts($t->credit, $t->student);
        self::assertSame($legacy ? null : $source, $facts["transfer_institution_id"]);
        $work = AcademicWorkView::fromLedger($t->credit->value, $facts);
        self::assertSame($facts["transfer_institution_id"], $work["transfer_institution_id"]);
        self::assertSame(
            $legacy ? "transfer_institution_unknown" : null,
            $policy->transferStatus($work["credit_type"], $work["transfer_institution_id"])?->value,
        );
        self::assertSame($before, $s->sql->rows("sis_academic_credits"));
    }
}
