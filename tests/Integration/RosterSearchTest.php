<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\Http\Controller\SisOperationsController;
use Domain\Access\Repository\AccessRepository;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Workspace\Service\OperationsService;
use Infrastructure\Persistence\Repository\SqlOperationsRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Http\ServerRequest;

final class RosterSearchTest extends TestCase
{
    private function fixture(?callable $afterRead = null): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new \SisFixture();
        $section = $s->section(100, 0, $s->access->actor);
        $permissions = $this->createStub(AccessRepository::class);
        $permissions
            ->method("allows")
            ->willReturnCallback(
                static fn(string $id, string $name): bool => in_array($name, $s->access->grants, true),
            );
        $mail = $this->createMock(MailTransport::class);
        $mail->expects(self::never())->method("send");
        $campaigns = new CampaignService(
            $this->createStub(CampaignRepository::class),
            $this->createStub(MessageCipher::class),
            $mail,
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $repository = new class (new SqlOperationsRepository($s->sql), $afterRead, $s) implements
            \Domain\Workspace\Repository\OperationsRepository
        {
            public function __construct(
                private SqlOperationsRepository $inner,
                private mixed $afterRead,
                private \SisFixture $s,
            ) {
            }
            public function page(
                string $context,
                EntityId $id,
                ?EntityId $after,
                string $search = "",
                string $gradeStatus = "all",
            ): array {
                $page = $this->inner->page($context, $id, $after, $search, $gradeStatus);
                if ($this->afterRead !== null) {
                    ($this->afterRead)($this->s, $id);
                }
                return $page;
            }
        };
        $service = new OperationsService(
            $repository,
            $s->access,
            $permissions,
            $s->registration,
            $s->admissions,
            $campaigns,
        );
        return [$s, $section, $service];
    }

    public function testSearchRunsBeforePagingAndLiteralWildcardsDoNotMatchEveryone(): void
    {
        [$s, $section, $service] = $this->fixture();
        $first = null;
        for ($i = 0; $i < 51; $i++) {
            $student = $s->student($s->user());
            $first ??= $student;
            $s->service->register($student->id, $section->id, true);
        }
        $identifier = $s->admissions->person($first->personId)->institutionalId->value;
        $before = $s->sql->rows("sis_history_events");
        $s->access->staff = false;
        $s->access->grants = ["admin:dashboard"];
        $page = $service->page("roster", $section->id);
        self::assertCount(50, $page["items"]);
        self::assertNotNull($page["next_after"]);
        $found = $service->page("roster", $section->id, search: $identifier, gradeStatus: "needs_grade");
        self::assertCount(1, $found["items"]);
        self::assertSame($first->id->value, $found["items"][0]["student_id"]);
        $person = $s->admissions->person($first->personId);
        self::assertNotEmpty(
            $service->page("roster", $section->id, search: $person->givenName . " " . $person->familyName)[
                "items"
            ],
        );
        self::assertSame([], $service->page("roster", $section->id, search: "%")["items"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testGradeFiltersAndExportShareTheSameCurrentProjection(): void
    {
        [$s, $section, $service] = $this->fixture();
        $student = $s->student($s->user());
        $enrollment = $s->service->register($student->id, $section->id, true);
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $grade = new EntityId($s->gradeCatalog()->byCode("P", $student->id)["id"]);
        $review = $s->academicCredits()->review($student->id, $section->id, $enrollment->id, $grade, 0);
        $s->academicCredits()->commit($student->id, $section->id, $review["review_token"]);
        $other = $s->student($s->user());
        $s->service->register($other->id, $section->id, true);
        $s->access->staff = false;
        $s->access->grants = ["admin:dashboard"];
        self::assertSame(
            [$student->id->value],
            array_column(
                $service->page("roster", $section->id, gradeStatus: "graded")["items"],
                "student_id",
            ),
        );
        self::assertSame(
            [$other->id->value],
            array_column(
                $service->page("roster", $section->id, gradeStatus: "needs_grade")["items"],
                "student_id",
            ),
        );
        $response = new SisOperationsController($service)->rosterCsv(
            new ServerRequest([], [], "/fixture/", "POST")->withParsedBody(["grade_status" => "graded"]),
            $section->id->value,
        );
        $csv = (string) $response->getBody();
        self::assertStringContainsString(
            $s->admissions->person($student->personId)->institutionalId->value,
            $csv,
        );
        self::assertStringNotContainsString(
            $s->admissions->person($other->personId)->institutionalId->value,
            $csv,
        );
        self::assertStringContainsString("no-store", $response->getHeaderLine("Cache-Control"));
    }

    public function testRegistrationAccessCannotInferGradingStatusByFilteringOrExport(): void
    {
        [$s, $section, $service] = $this->fixture();
        $s->sql->update(
            "sis_sections",
            ["faculty_user_id" => $s->user()->value],
            ["id" => $section->id->value],
        );
        $s->access->staff = false;
        $s->access->grants = ["admin:dashboard", "sis:registration:manage"];
        self::assertFalse($service->page("roster", $section->id, gradeStatus: "active")["can_grade"]);
        foreach (["graded", "needs_grade"] as $filter) {
            try {
                $service->page("roster", $section->id, gradeStatus: $filter);
                self::fail("Grade status was disclosed.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(AccessDeniedException::class);
        new SisOperationsController($service)->rosterCsv(
            new ServerRequest([], [], "/fixture/", "POST")->withParsedBody(["grade_status" => "graded"]),
            $section->id->value,
        );
    }
    public function testActorOrTeachingAssignmentChangesDuringReadCannotReleaseRows(): void
    {
        foreach (["actor", "assignment"] as $mode) {
            [$s, $section, $service] = $this->fixture(static function (
                \SisFixture $s,
                EntityId $section,
            ) use ($mode): void {
                if ($mode === "actor") {
                    $s->access->actor = $s->user();
                } else {
                    $s->sql->update(
                        "sis_sections",
                        ["faculty_user_id" => $s->user()->value],
                        ["id" => $section->value],
                    );
                }
            });
            $student = $s->student($s->user());
            $s->service->register($student->id, $section->id, true);
            $s->access->staff = false;
            $s->access->grants = ["admin:dashboard"];
            try {
                $service->page("roster", $section->id, gradeStatus: "needs_grade");
                self::fail("Changed authority returned private roster rows.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
}
