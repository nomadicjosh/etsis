<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Admissions\AdmissionCasework;
use Domain\Admissions\ValueObject\AdmissionChecklistProof;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AdmissionChecklistProofTest extends TestCase
{
    private function proof(): array
    {
        return AdmissionChecklistProof::capture(
            AdmissionCasework::fromInput(
                EntityId::generate(),
                [
                    "items" => [
                        [
                            "key" => "transcript",
                            "label" => "Fictional transcript",
                            "needed" => true,
                            "visible" => false,
                            "status" => "complete",
                        ],
                    ],
                ],
                null,
                1,
            ),
            ["required" => true, "revision" => 3],
        );
    }
    public function testFrozenValidationRejectsMalformedOrContradictoryAcceptanceProofs(): void
    {
        $changes = ["extra", "negative", "version", "missing_case", "duplicate",
            "status", "visibility", "pending", "received"];
        foreach ($changes as $change) {
            $p = $this->proof();
            match ($change) {
                "extra" => ($p["private_notes"] =
                    "Never a checklist proof field."),
                "negative" => ($p["policy_revision"] = -1),
                "version" => ($p["case_version"] = "1"),
                "missing_case" => ($p["case_version"] = null),
                "duplicate" => ($p["items"][] = $p["items"][0]),
                "status" => ($p["items"][0]["status"] = "approved"),
                "visibility" => ($p["items"][0]["visible"] = 1),
                default => ($p["items"][0]["status"] = $change),
            };
            try {
                AdmissionChecklistProof::validate($p, "accepted");
                self::fail("Invalid frozen decision evidence was accepted.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
    public function testDigestIgnoresObjectKeyOrderAndHistorySummaryOmitsItemNames(): void
    {
        $p = $this->proof();
        $other = array_reverse($p, true);
        $other["items"][0] = array_reverse($p["items"][0], true);
        self::assertSame(
            AdmissionChecklistProof::digest($p),
            AdmissionChecklistProof::digest($other),
        );
        self::assertSame(
            [
                "required" => true,
                "ready" => true,
                "needed" => 1,
                "complete" => 1,
                "not_needed" => 0,
            ],
            AdmissionChecklistProof::summary($p),
        );
        self::assertStringNotContainsString(
            "Fictional transcript",
            json_encode(AdmissionChecklistProof::summary($p)),
        );
    }
}
