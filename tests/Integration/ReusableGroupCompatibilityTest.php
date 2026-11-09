<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
use Domain\DegreeAudit\Event\RequirementsWerePublished;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\RequirementsPublicationId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
use PHPUnit\Framework\TestCase;
final class ReusableGroupCompatibilityTest extends TestCase
{
    /** A fictional original publication, using the exact metadata/token shape of the old writer. */
    private function original(ReusableGroupFixture $f, int $schema, bool $accepted): array
    {
        $definition = $f->oldDefinition($schema === 2);
        $op = EntityId::generate();
        $actor = $f->sis->access->requirePermission("sis:catalog:manage");
        $context = new EventContext(
            $actor,
            $f->sis->clock->now(),
            $op,
            reason: "Fictional original approval",
        );
        $labels = new SqlRequirementsRepository($f->sis->sql)->context($f->sis->program, $f->year);
        $data = [
            "kind" => "degree-audit-publication-review.v" . $schema,
            "template_id" => $f->degree->value,
            "actor_id" => $actor->value,
            "operation_id" => $op->value,
            "revision" => 0,
            "expected_version" => 0,
            "previous_version_id" => null,
            "definition" => $definition,
            "context" => $labels,
            "reason" => "Fictional original approval",
            "effective_at" => $context->describe()["effective_at"],
        ];
        $metadata = ["__aggregate_type" => "degree_audit_requirements"];
        if ($schema === 2) {
            $metadata["schema_version"] = 2;
        }
        $event = RequirementsWerePublished::occur(
            new RequirementsPublicationId($f->degree->value),
            [
                "program_id" => $f->sis->program->value,
                "academic_year_id" => $f->year->value,
                "version_id" => $op->value,
                "previous_version_id" => null,
                "labels" => array_diff_key($labels, array_flip(["program_id", "academic_year_id"])),
                "definition" => $definition,
            ],
            $metadata,
        )->withPlayhead(1);
        $receipt = null;
        if ($accepted) {
            $receipt = $f->history->append(
                new StreamReference($event->aggregateId(), "degree_audit_requirements"),
                $op,
                0,
                new EventContext(
                    $actor,
                    new DateTimeImmutable($data["effective_at"]),
                    $op,
                    reason: $data["reason"],
                ),
                [$event],
            );
            new SqlRequirementsRepository($f->sis->sql)->published($f->degree, $op, 1);
        }
        $f->sis->sql->update(
            "sis_degree_audit_templates",
            ["draft_json" => json_encode($definition, JSON_THROW_ON_ERROR)],
            ["id" => $f->degree->value],
        );
        return [$data, $f->catalog->fixture->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)), $receipt];
    }
    public function testFrozenV1AndV2PublicationsAndAcceptedReceiptsSurviveDraftConversionAndV3Publication(): void
    {
        foreach ([1, 2] as $schema) {
            $f = new ReusableGroupFixture();
            [$data, $token, $receipt] = $this->original($f, $schema, true);
            $old = $f->degrees->version($f->degree, new EntityId($data["operation_id"]));
            self::assertSame($schema, $old["schema_version"]);
            self::assertSame($schema === 2, array_key_exists("policies", $old["definition"]));
            $before = $f->digest("sis_history_events");
            $converted = $f->degrees->reuseLegacy($f->degree, 0);
            self::assertSame($before, $f->digest("sis_history_events"));
            self::assertSame($receipt, $f->degrees->publish($f->degree, $token));
            self::assertSame($old, $f->degrees->version($f->degree, new EntityId($data["operation_id"])));
            $d = $f->definition;
            $f->degrees->save($f->degree, $converted["revision"], new AuditRequirementsV3($d));
            $f->publishDegree();
            self::assertSame($old, $f->degrees->version($f->degree, new EntityId($data["operation_id"])));
            self::assertSame($receipt, $f->degrees->publish($f->degree, $token));
            $versions = $f->degrees->versions($f->degree)["items"];
            self::assertSame([1, 2], array_column($versions, "version"));
            self::assertSame(
                $data["operation_id"],
                $f->history
                    ->page(
                        new StreamReference(
                            new RequirementsPublicationId($f->degree->value),
                            "degree_audit_requirements",
                        ),
                    )
                    ->events[1]->event->payload()["previous_version_id"],
            );
        }
    }
    public function testAbandonedInlineReviewsCannotCreateNewPublications(): void
    {
        foreach ([1, 2] as $schema) {
            $f = new ReusableGroupFixture();
            [, $token] = $this->original($f, $schema, false);
            $before = $f->digest("sis_history_events");
            try {
                $f->degrees->publish($f->degree, $token);
                self::fail("New inline publication accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $f->digest("sis_history_events"));
                self::assertSame(0, $f->degrees->view($f->degree)["publication_position"]);
            }
        }
    }
    public function testInlineSchemasAndReferenceSchemaCannotBeReinterpretedAsEachOther(): void
    {
        $f = new ReusableGroupFixture();
        $id = new RequirementsPublicationId($f->degree->value);
        $actor = $f->sis->access->requirePermission("sis:catalog:manage");
        $cases = [[1, $f->definition], [2, $f->definition], [3, $f->oldDefinition()]];
        foreach ($cases as [$schema, $definition]) {
            $op = EntityId::generate();
            $c = new SqlRequirementsRepository($f->sis->sql)->context($f->sis->program, $f->year);
            $e = RequirementsWerePublished::occur(
                $id,
                [
                    "program_id" => $f->sis->program->value,
                    "academic_year_id" => $f->year->value,
                    "version_id" => $op->value,
                    "previous_version_id" => null,
                    "labels" => array_diff_key($c, array_flip(["program_id", "academic_year_id"])),
                    "definition" => $definition,
                ],
                ["__aggregate_type" => "degree_audit_requirements", "schema_version" => $schema],
            )->withPlayhead(1);
            $before = $f->digest("sis_history_events");
            try {
                $f->history->append(
                    new StreamReference($id, "degree_audit_requirements"),
                    $op,
                    0,
                    new EventContext($actor, $f->sis->clock->now(), $op, reason: "Fictional invalid schema"),
                    [$e],
                );
                self::fail("Schema reinterpretation accepted.");
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame($before, $f->digest("sis_history_events"));
            }
        }
    }

    public function testCloningAnOriginalPublicationKeepsUnknownPoliciesUntilExplicitConversion(): void
    {
        $f = new ReusableGroupFixture();
        [$data] = $this->original($f, 1, true);
        $calendar = new AcademicCalendarFixture($f->sis);
        $year = new EntityId($calendar->year(2028)["id"]);
        $clone = $f->degrees->cloneTo(
            $f->degree,
            new EntityId($data["operation_id"]),
            $f->sis->program,
            $year,
        );
        self::assertArrayHasKey("courses", $clone["definition"]);
        self::assertArrayNotHasKey("policies", $clone["definition"]);
        self::assertNotSame($data["definition"]["groups"][0]["id"], $clone["definition"]["groups"][0]["id"]);
        $converted = $f->degrees->reuseLegacy(new EntityId($clone["id"]), 0);
        self::assertNull($converted["definition"]["policies"]["repeat_credit"]);
        self::assertNull($converted["definition"]["groups"][0]["version_id"]);
        self::assertNull($converted["published_version_id"]);
        self::assertSame([], $f->degrees->versions(new EntityId($clone["id"]))["items"]);
    }
}
