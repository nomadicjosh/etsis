<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\RegistrationBatchSelection;
use PHPUnit\Framework\TestCase;

final class RegistrationReadinessTest extends TestCase
{
    private function fixture(): array
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
        $s = new \SisFixture();
        $student = $s->student();
        $section = $s->section(20, 0);
        return [$s, $student, $section, new RegistrationBatchSelection($section->id, $student->id)];
    }

    public function testStudentAndStaffReadinessFollowTheirActualRegistrationChannel(): void
    {
        [$s, , , $selection] = $this->fixture();
        $s->clock->time = $s->clock->time->modify("+2 years");
        $before = $s->sql->rows("sis_history_events");
        $self = $s->registrationBatches()->review($selection, false)["students"][0];
        $staff = $s->registrationBatches()->review($selection, true)["students"][0];
        self::assertSame(
            "blocked",
            array_column($self["readiness"], "status", "code")["registration_window"],
        );
        self::assertSame(
            "not_applicable",
            array_column($staff["readiness"], "status", "code")["registration_window"],
        );
        foreach ([$self, $staff] as $row) {
            self::assertSame(
                array_column(
                    array_filter(
                        $row["readiness"],
                        static fn(array $check): bool => $check["status"] === "blocked",
                    ),
                    "message",
                ),
                $row["issues"],
            );
        }
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testMissingPrerequisiteDoesNotMisidentifyAnActiveStudentAsInactive(): void
    {
        [$s, $student, $section, $selection] = $this->fixture();
        $prior = $s->section(20, 0);
        $s->registration->addPrerequisite($section->courseId, $prior->courseId);
        $review = $s->registrationBatches()->review($selection, false)["students"][0];
        $checks = array_column($review["readiness"], null, "code");
        self::assertSame("met", $checks["student_activity"]["status"]);
        self::assertSame("blocked", $checks["prerequisites"]["status"]);
        self::assertStringContainsString("Required courses", $checks["prerequisites"]["next_step"]);
        self::assertSame([$checks["prerequisites"]["message"]], $review["issues"]);
        self::assertNull($s->registration->enrollment($student->id, $section->id));
    }

    public function testExistingRegistrationIsNotGivenInventedCurrentReadinessChecks(): void
    {
        [$s, $student, $section, $selection] = $this->fixture();
        $s->service->register($student->id, $section->id);
        $s->clock->time = $s->clock->time->modify("+2 years");
        $before = $s->sql->rows("sis_history_events");
        $review = $s->registrationBatches()->review($selection, false)["students"][0];
        self::assertSame([], $review["readiness"]);
        self::assertSame([], $review["issues"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }
}
