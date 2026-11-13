<?php

declare(strict_types=1);

use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Rules\CourseEligibilityRule;
use Domain\Rules\RuleSet;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CourseEligibilityRuleTest extends TestCase
{
    private static function id(int $id): string
    {
        return str_pad((string) $id, 26, "0", STR_PAD_LEFT);
    }
    private function leaf(string $field, string $operator, mixed $value): array
    {
        return ["id" => $field, "operator" => $operator, "value" => $value];
    }
    private function course(
        ?int $subject = 10,
        ?string $level = "300",
        string $code = "MATH.101",
    ): CourseDefinition {
        return new CourseDefinition(
            $code,
            "Fictional course",
            3000,
            [],
            subject: $subject === null
                ? null
                : new SubjectSnapshot(new EntityId(self::id($subject)), "MATH", "Mathematics"),
            classifications: new CourseClassifications(
                courseLevel: $level === null
                    ? null
                    : new ClassificationSnapshot(new EntityId(self::id(20)), $level, "Retained course level"),
            ),
        );
    }
    public function testSubjectExclusionsUseCourseIdentitiesEvenAfterRenaming(): void
    {
        $rule = new CourseEligibilityRule([
            "condition" => "AND",
            "rules" => [
                $this->leaf("subject_id", "equal", self::id(10)),
                $this->leaf("course_id", "not_equal", self::id(2)),
            ],
        ]);
        self::assertTrue($rule->decision(new EntityId(self::id(1)), $this->course()));
        self::assertFalse($rule->decision(new EntityId(self::id(2)), $this->course(code: "RENAMED.999")));
        self::assertFalse($rule->decision(new EntityId(self::id(1)), $this->course(subject: 11)));
        self::assertSame(["course_id" => [self::id(2)], "subject_id" => [self::id(10)]], $rule->references());
    }
    public function testNumericComparisonsUseTheRetainedLevelDefinitionAndNeverTheCourseNumber(): void
    {
        $rule = new CourseEligibilityRule([
            "condition" => "AND",
            "rules" => [
                $this->leaf("subject_id", "equal", self::id(10)),
                $this->leaf("course_level_number", "greater", "200"),
            ],
        ]);
        self::assertTrue(
            $rule->decision(new EntityId(self::id(1)), $this->course(level: "300", code: "MATH.100")),
        );
        self::assertFalse(
            $rule->decision(new EntityId(self::id(1)), $this->course(level: "200", code: "MATH.999")),
        );
        self::assertNull($rule->decision(new EntityId(self::id(1)), $this->course(level: "UPPER")));
        self::assertNull($rule->decision(new EntityId(self::id(1)), $this->course(level: null)));
        self::assertNull($rule->decision(new EntityId(self::id(1)), $this->course(subject: null)));
    }
    public function testMissingClassificationsRemainUnknownForExclusionsButDecisiveBranchesStillApply(): void
    {
        $unknown = $this->leaf("department_id", "not_in", [self::id(40), self::id(41)]);
        $false = $this->leaf("course_id", "equal", self::id(2));
        $true = $this->leaf("course_id", "equal", self::id(1));
        foreach (
            [
                ["AND", [$unknown], null],
                ["AND", [$unknown, $false], false],
                ["OR", [$unknown, $true], true],
                ["OR", [$unknown, $false], null],
            ]
            as [$condition, $rules, $expected]
        ) {
            self::assertSame(
                $expected,
                new CourseEligibilityRule(compact("condition", "rules"))->decision(
                    new EntityId(self::id(1)),
                    $this->course(),
                ),
            );
        }
        self::assertFalse(
            new RuleSet(
                ["condition" => "AND", "rules" => [$unknown]],
                CourseEligibilityRule::FIELDS,
            )->matches([]),
        );
    }
    public function testInvalidFieldsIdentitiesOperatorsAndNumericValuesAreRejected(): void
    {
        foreach (
            [
                $this->leaf("email", "equal", "fictional@example.invalid"),
                $this->leaf("course_id", "raw_sql", "SELECT * FROM users"),
                $this->leaf("subject_id", "equal", "ENG"),
                $this->leaf("course_level_number", "greater", "200.5"),
                $this->leaf("course_level_number", "greater", -1),
                $this->leaf("course_level_number", "greater", 1000000),
            ]
            as $leaf
        ) {
            try {
                new CourseEligibilityRule(["condition" => "AND", "rules" => [$leaf]]);
                self::fail("Unsupported course condition was accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
