<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Registration\ValueObject\FacultyConflictAssessment;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class FacultyConflictAssessmentTest extends TestCase
{
    private function facts(): array
    {
        $section = EntityId::generate()->value;
        return [
            "mode" => "warn",
            "revision" => 1,
            "faculty_ids" => [EntityId::generate()->value],
            "unassigned_meetings" => 0,
            "sources" => [
                [
                    "section_id" => $section,
                    "publication_id" => EntityId::generate()->value,
                    "version" => 1,
                    "definition_hash" => str_repeat("a", 64),
                ],
            ],
            "pairs" => 1,
            "conflicts" => [
                [
                    "section_id" => $section,
                    "section_code" => "ENG.101",
                    "term_code" => "26/FA",
                    "own_occurrence_id" => EntityId::generate()->value,
                    "other_occurrence_id" => EntityId::generate()->value,
                    "starts_at" => "2026-08-10T17:00:00Z",
                    "ends_at" => "2026-08-10T18:00:00Z",
                ],
            ],
        ];
    }

    public function testProtectedCanonicalObjectOrderingKeepsFingerprintsAndPublicSummaryStable(): void
    {
        $facts = $this->facts();
        $original = new FacultyConflictAssessment($facts);
        foreach (["sources", "conflicts"] as $key) {
            ksort($facts[$key][0], SORT_STRING);
        }
        ksort($facts, SORT_STRING);
        $restored = new FacultyConflictAssessment($facts);
        self::assertSame($original->fingerprint(), $restored->fingerprint());
        self::assertSame($original->summary(), $restored->summary());
        self::assertFalse($restored->blocked());
        $facts["mode"] = "block";
        self::assertTrue(new FacultyConflictAssessment($facts)->blocked());
    }

    public function testInvalidOrIncompleteEvidenceNeverBecomesAClearAssessment(): void
    {
        $original = $this->facts();
        $invalid = [];
        $facts = $original;
        $facts["pairs"] = 0;
        $invalid[] = $facts;
        $facts = $original;
        $facts["faculty_ids"] = [];
        $invalid[] = $facts;
        $facts = $original;
        $facts["conflicts"][0]["starts_at"] = $facts["conflicts"][0]["ends_at"];
        $invalid[] = $facts;
        $facts = $original;
        $facts["sources"] = [];
        $invalid[] = $facts;
        foreach ($invalid as $facts) {
            try {
                new FacultyConflictAssessment($facts);
                self::fail("Incomplete evidence was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
