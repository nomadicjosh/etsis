<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\ExternalCourseGrade;
use Domain\Gradebook\ValueObject\ExternalGradeEvidence;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalGradeEvidenceTest extends TestCase
{
    public function testCuratedSummaryOmitsIdentifiersAndFingerprints(): void
    {
        $facts = [];
        foreach (
            [
                "candidate_id",
                "binding_id",
                "section_id",
                "section_link_id",
                "person_id",
                "person_link_id",
                "student_id",
                "enrollment_id",
            ] as $key
        ) {
            $facts[$key] = EntityId::generate()->value;
        }
        $facts += [
            "kind" => "moodle_course_total",
            "moodle_course_id" => "42",
            "moodle_user_id" => "73",
            "source_hash" => str_repeat("a", 64),
            "observed_at" => "2026-08-10T10:00:00Z",
            "grade" => [
                "state" => "available",
                "item_id" => "97",
                "grade" => "91.25000",
                "minimum" => "0.00000",
                "maximum" => "100.00000",
                "revision" => str_repeat("b", 64),
            ],
        ];
        $summary = new ExternalGradeEvidence($facts)->summary();
        self::assertSame(
            ["kind", "observed_at", "grade"],
            array_keys($summary),
        );
        self::assertSame(
            ["state", "grade", "minimum", "maximum"],
            array_keys($summary["grade"]),
        );
        self::assertSame("91.25000", $summary["grade"]["grade"]);
    }

    public static function invalidTotals(): iterable
    {
        $base = [
            "state" => "available",
            "item_id" => "97",
            "grade" => "0.00000",
            "minimum" => "0.00000",
            "maximum" => "100.00000",
            "revision" => str_repeat("a", 64),
        ];
        yield "exponent" => [array_replace($base, ["grade" => "1e6"])];
        yield "float" => [array_replace($base, ["grade" => 91.25])];
        yield "locale" => [array_replace($base, ["grade" => "91,25"])];
        yield "excess precision" => [
            array_replace($base, ["grade" => "91.250001"]),
        ];
        yield "empty scale" => [array_replace($base, ["maximum" => "0.00000"])];
        yield "hidden numeric value" => [
            array_replace($base, ["state" => "hidden"]),
        ];
        yield 'external identifier overflow' => [array_replace($base, ['item_id' => '9223372036854775808'])];
        yield "extra feedback" => [
            $base + ["feedback" => "Fictional private feedback"],
        ];
    }

    #[DataProvider("invalidTotals")]
    public function testOnlyExactBoundedReleasedDecimalEvidenceIsAccepted(
        array $facts,
    ): void {
        $this->expectException(CorruptHistoryException::class);
        new ExternalCourseGrade($facts);
    }
}
