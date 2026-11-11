<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\ValueObject\AuditCalculationPolicy;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\RepeatAttemptOrder;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetainedRepeatOrderTest extends TestCase
{
    private function definition(): array
    {
        $d = AuditRequirementsV3::blank("Fictional degree")->definition;
        $d["min_credits_milli"] = 3000;
        $d["groups"] = [
            ["group_id" => EntityId::generate()->value, "version_id" => EntityId::generate()->value],
        ];
        $d["policies"]["repeat_credit"] = "highest_earned";
        $d["policies"]["repeat_gpa"] = "latest_eligible";
        $d["policies"]["transfer_overall_gpa"] = "exclude";
        return $d;
    }
    public function testOnlyNewBlankDraftsDefaultToAcademicPeriodAndBothChoicesAreRetained(): void
    {
        self::assertSame(
            RepeatAttemptOrder::AcademicPeriod,
            new AuditRequirementsV3($this->definition())->attemptOrder,
        );
        foreach (RepeatAttemptOrder::cases() as $order) {
            $d = $this->definition();
            $d["policies"]["repeat_attempt_order"] = $order->value;
            new AuditRequirementsV3($d)->assertNewPublication();
            self::assertSame(
                $order,
                AuditCalculationPolicy::fromRequirements(["definition" => $d])->attemptOrder,
            );
        }
    }
    public function testEarlierMissingOrderStaysReadableAndUnknownButNewLatestPublicationMustChoose(): void
    {
        $d = $this->definition();
        unset($d["policies"]["repeat_attempt_order"]);
        $old = new AuditRequirementsV3($d);
        $old->assertPublishable();
        self::assertNull($old->attemptOrder);
        self::assertArrayNotHasKey("repeat_attempt_order", $old->definition["policies"]);
        self::assertNull(AuditCalculationPolicy::fromRequirements(["definition" => $d])->attemptOrder);
        $this->expectException(InvalidArgumentException::class);
        $old->assertNewPublication();
    }
    public function testNullRemainsUnknownAndUnsupportedOrderingCannotBecomeAPolicy(): void
    {
        $d = $this->definition();
        $d["policies"]["repeat_attempt_order"] = null;
        self::assertNull(new AuditRequirementsV3($d)->attemptOrder);
        $d["policies"]["repeat_attempt_order"] = "grade_posted_at";
        $this->expectException(InvalidArgumentException::class);
        new AuditRequirementsV3($d);
    }
    public function testUnknownOrderIsNotNeededForAnAllAttemptsPublication(): void
    {
        $d = $this->definition();
        unset($d["policies"]["repeat_attempt_order"]);
        $d["policies"]["repeat_gpa"] = "all_eligible";
        new AuditRequirementsV3($d)->assertNewPublication();
        self::assertNull(AuditCalculationPolicy::fromRequirements(["definition" => $d])->attemptOrder);
    }
    public function testMissingOrderingIsAnActionablePrivateFieldError(): void
    {
        $d = $this->definition();
        unset($d["policies"]["repeat_attempt_order"]);
        $handler = new \Qubus\Routing\Handlers\CallableRequestHandler(static function () use ($d): never {
            new AuditRequirementsV3($d)->assertNewPublication();
            throw new \LogicException("The missing policy was accepted.");
        });
        $request = new \Qubus\Http\ServerRequest([], [], "/sis/degree-audits/example/review/", "POST");
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process($request, $handler);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString("no-store", $response->getHeaderLine("Cache-Control"));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame("repeat_attempt_order", $body["errors"][0]["field"]);
        self::assertStringContainsString("Choose how to order", $body["errors"][0]["message"]);
    }

}
