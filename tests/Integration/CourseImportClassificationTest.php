<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Course;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogImportRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSubjectRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CourseImportClassificationTest extends TestCase
{
    private const array CORE = [
        "external_id" => 0,
        "code" => 1,
        "title" => 2,
        "credits" => 3,
    ];
    private const array FULL = [
        "external_id" => 0,
        "code" => 1,
        "title" => 2,
        "credits" => 3,
        "subject_code" => 4,
        "department_code" => 5,
        "academic_level_code" => 6,
        "course_level_code" => 7,
    ];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    /** @return array<string,EntityId> */
    private function library(SisFixture $f): array
    {
        $subject = new SubjectSnapshot(
            EntityId::generate(),
            "MATH",
            "Mathematics & fictional studies",
        );
        new SqlSubjectRepository($f->sql)->save($subject, true, true);
        $ids = ["subject" => $subject->id];
        $values = [
            "department" => ["SCI", "Sciences"],
            "academic_level" => ["UG", "Undergraduate"],
            "course_level" => ["100", "Introductory"],
        ];
        foreach ($values as $kind => [$code, $name]) {
            $value = new ClassificationSnapshot(
                EntityId::generate(),
                $code,
                $name,
            );
            new SqlClassificationRepository($f->sql)->save(
                ClassificationKind::from($kind),
                $value,
                true,
                true,
            );
            $ids[$kind] = $value->id;
        }
        return $ids;
    }

    private function csv(string ...$rows): string
    {
        return "External ID,Code,Title,Credits,Subject code,Department code,Academic level code,Course level code\n"
        . implode("\n", $rows) . "\n";
    }

    public function testClassificationsAreReviewedAndAssignedOnlyToTheNewInactiveDraft(): void
    {
        $f = new SisFixture();
        $ids = $this->library($f);
        $events = $f->sql->rows("sis_history_events");
        $subjects = $f->sql->rows("sis_subjects");
        $classes = $f->sql->rows("sis_catalog_classifications");
        $csv = $this->csv("1,MATH.101,Algebra,3,math,sci,ug,100");
        $service = $f->imports();
        $review = $service->preview("source", $csv, self::FULL);
        self::assertTrue($review["ready"]);
        self::assertSame(
            "MATH",
            $review["plan"][0]["source_references"]["subject"],
        );
        self::assertSame(
            "Undergraduate",
            $review["plan"][0]["references"]["academic_level"]["title"],
        );
        self::assertSame([], $f->sql->rows("sis_courses"));
        self::assertSame([], $f->sql->rows("sis_course_subjects"));
        $service->commit("source", $csv, self::FULL, $review["review_token"]);
        $course = $f->sql->one("sis_courses", ["code" => "MATH.101"]);
        self::assertSame(0, (int) $course["active"]);
        self::assertSame(
            $ids["subject"]->value,
            $f->sql->one("sis_course_subjects", ["id" => $course["id"]])[
                "subject_id"
            ],
        );
        $assigned = $f->sql->one("sis_course_classifications", [
            "id" => $course["id"],
        ]);
        foreach (ClassificationKind::courseKinds() as $kind) {
            self::assertSame(
                $ids[$kind->value]->value,
                $assigned[$kind->value . "_id"],
            );
        }
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($subjects, $f->sql->rows("sis_subjects"));
        self::assertSame(
            $classes,
            $f->sql->rows("sis_catalog_classifications"),
        );
    }

    public function testBlankOrUnmappedColumnsDoNotInferClassificationsFromCourseCodes(): void
    {
        $f = new SisFixture();
        $this->library($f);
        $service = $f->imports();
        $mappings = ["blank" => self::FULL, "unmapped" => self::CORE];
        foreach ($mappings as $source => $mapping) {
            $csv = $source === "blank" ? $this->csv("1,MATH.101,Algebra,3,,,,")
            : $this->csv("2,MATH.102,Algebra II,3,MATH,SCI,UG,100");
            $review = $service->preview($source, $csv, $mapping);
            self::assertTrue($review["ready"]);
            self::assertSame([], $review["plan"][0]["references"]);
            $service->commit($source, $csv, $mapping, $review["review_token"]);
        }
        self::assertSame([], $f->sql->rows("sis_course_subjects"));
        self::assertSame([], $f->sql->rows("sis_course_classifications"));
    }

    public function testMissingRetiredAndWrongCategoryReferencesAreActionableConflictsAndCanBeLeftOut(): void
    {
        $cases = ["missing", "retired", "wrong_category"];
        foreach ($cases as $case) {
            $f = new SisFixture();
            $ids = $this->library($f);
            if ($case === "missing") {
                $f->sql->execute("DELETE FROM sis_subjects WHERE id = ?", [
                    $ids["subject"]->value,
                ]);
            }
            if ($case === "retired") {
                $f->sql->update(
                    "sis_subjects",
                    ["active" => 0],
                    ["id" => $ids["subject"]->value],
                );
            }
            if ($case === "wrong_category") {
                $f->sql->update(
                    "sis_catalog_classifications",
                    ["kind" => "award"],
                    ["id" => $ids["academic_level"]->value],
                );
            }
            $csv = $this->csv(
                "1,MATH.101,Algebra,3,MATH,SCI,UG,100",
                "2,ENG.101,Writing,3,,,,",
            );
            $review = $f->imports()->preview("source", $csv, self::FULL);
            self::assertFalse($review["ready"]);
            self::assertSame("conflict", $review["plan"][0]["action"]);
            self::assertNotEmpty($review["plan"][0]["reference_errors"]);
            self::assertNull($review["review_token"]);
            $choices = [
                ["external_id" => "1", "action" => "skip", "course_id" => null],
            ];
            $review = $f
                ->imports()
                ->preview("source", $csv, self::FULL, choices: $choices);
            self::assertTrue($review["ready"]);
            $result = $f
                ->imports()
                ->commit(
                    "source",
                    $csv,
                    self::FULL,
                    $review["review_token"],
                    choices: $choices,
                );
            self::assertSame(1, $result["imported"]);
            self::assertSame(1, $result["omitted"]);
            self::assertSame([], $f->sql->rows("sis_course_subjects"));
        }
    }

    public function testLinkingRequiresRequestedAssignmentsToMatchAndNeverChangesExistingRelationships(): void
    {
        $f = new SisFixture();
        $ids = $this->library($f);
        $course = EntityId::generate();
        $f->registration->addCourse(
            new Course($course, "MATH.101", "Algebra", 3000),
        );
        $csv = $this->csv("1,MATH.101,Algebra,3,MATH,SCI,UG,100");
        $service = $f->imports();
        $review = $service->preview("source", $csv, self::FULL);
        self::assertSame("conflict", $review["plan"][0]["action"]);
        self::assertSame([], $f->sql->rows("sis_course_subjects"));
        $f->sql->insert("sis_course_subjects", [
            "id" => $course->value,
            "subject_id" => $ids["subject"]->value,
        ]);
        $f->sql->insert("sis_course_classifications", [
            "id" => $course->value,
            "department_id" => $ids["department"]->value,
            "academic_level_id" => $ids["academic_level"]->value,
            "course_level_id" => $ids["course_level"]->value,
        ]);
        $choices = [
            [
                "external_id" => "1",
                "action" => "link",
                "course_id" => $course->value,
            ],
        ];
        $before = $f->sql->rows("sis_course_classifications");
        $subjectBefore = $f->sql->rows("sis_course_subjects");
        $review = $service->preview(
            "source",
            $csv,
            self::FULL,
            choices: $choices,
        );
        self::assertTrue($review["ready"]);
        $result = $service->commit(
            "source",
            $csv,
            self::FULL,
            $review["review_token"],
            choices: $choices,
        );
        self::assertSame(1, $result["linked"]);
        self::assertSame($before, $f->sql->rows("sis_course_classifications"));
        self::assertSame($subjectBefore, $f->sql->rows("sis_course_subjects"));
    }

    public function testReferenceChangesRequireReviewAndAcceptedRetriesKeepOriginalResults(): void
    {
        foreach (["name", "code", "retirement"] as $case) {
            $f = new SisFixture();
            $ids = $this->library($f);
            $service = $f->imports();
            $csv = $this->csv("1,MATH.101,Algebra,3,MATH,SCI,UG,100");
            $review = $service->preview("source", $csv, self::FULL);
            $changes = match ($case) {
                "name" => ["title" => "Updated sciences"],
                "code" => ["code" => "NEW.SCI"],
                "retirement" => ["active" => 0],
            };
            $f->sql->update("sis_catalog_classifications", $changes, [
                "id" => $ids["department"]->value,
            ]);
            try {
                $service->commit(
                    "source",
                    $csv,
                    self::FULL,
                    $review["review_token"],
                );
                self::fail(
                    "Changed reference setup was accepted from an older review.",
                );
            } catch (ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_courses"));
                self::assertSame([], $f->sql->rows("sis_import_mappings"));
            }
        }
        $f = new SisFixture();
        $ids = $this->library($f);
        $service = $f->imports();
        $csv = $this->csv("1,MATH.101,Algebra,3,MATH,SCI,UG,100");
        $review = $service->preview("source", $csv, self::FULL);
        $result = $service->commit(
            "source",
            $csv,
            self::FULL,
            $review["review_token"],
        );
        $f->sql->update(
            "sis_subjects",
            ["active" => 0, "code" => "LATER"],
            ["id" => $ids["subject"]->value],
        );
        $f->clock->time = $f->clock->time->modify("+1 day");
        $before = $f->sql->rows("sis_course_subjects");
        self::assertSame(
            $result,
            $service->commit(
                "source",
                $csv,
                self::FULL,
                $review["review_token"],
            ),
        );
        self::assertSame($before, $f->sql->rows("sis_course_subjects"));
        $next = $service->preview("source", $csv, self::FULL);
        self::assertTrue($next["ready"]);
        self::assertSame("skip", $next["plan"][0]["action"]);
        self::assertSame([], $next["plan"][0]["references"]);
    }

    public function testLockingReferenceReadsRejectAChangeCommittedDuringAWait(): void
    {
        $f = new SisFixture();
        $ids = $this->library($f);
        $inner = new SqlCatalogImportRepository($f->sql);
        $repository = $this->createStub(CatalogImportRepository::class);
        $repository
            ->method("inspect")
            ->willReturnCallback($inner->inspect(...));
        $repository
            ->method("lockRecords")
            ->willReturnCallback($inner->lockRecords(...));
        $repository
            ->method("lockReferences")
            ->willReturnCallback(static function (array $values) use (
                $f,
                $ids,
                $inner,
            ): array {
                $f->sql->update(
                    "sis_subjects",
                    ["active" => 0],
                    ["id" => $ids["subject"]->value],
                );
                return $inner->lockReferences($values);
            });
        $service = $f->imports(repository: $repository);
        $csv = $this->csv("1,MATH.101,Algebra,3,MATH,SCI,UG,100");
        $review = $service->preview("source", $csv, self::FULL);
        try {
            $service->commit(
                "source",
                $csv,
                self::FULL,
                $review["review_token"],
            );
            self::fail(
                "Reference values captured before a lock wait were trusted.",
            );
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_courses"));
            self::assertSame([], $f->sql->rows("sis_catalog_operations"));
        }
    }

    public function testOptionalColumnsCanBeUnmappedOrExplicitlyReusedWithoutWeakeningRequiredMappings(): void
    {
        $f = new SisFixture();
        $this->library($f);
        $csv = $this->csv("1,MATH.101,Algebra,3,MATH,SCI,UG,100");
        $invalid = self::FULL;
        $invalid["subject_code"] = 99;
        $bad = $f->imports()->preview("source", $csv, $invalid);
        self::assertFalse($bad["ready"]);
        self::assertSame("subject_code", $bad["errors"][0]["field"]);
        $mapped = self::CORE + ["subject_code" => 1];
        $valid = $f->imports()->preview("source", $csv, $mapped);
        self::assertSame(
            "MATH.101",
            $valid["plan"][0]["source_references"]["subject"],
        );
        self::assertFalse($valid["ready"]);
        $blank = $f
            ->imports()
            ->preview(
                "source",
                $this->csv("2,MATH.102,More algebra,3,bad code,SCI,UG,100"),
                self::FULL,
            );
        self::assertFalse($blank["ready"]);
        self::assertStringContainsString(
            "classification codes",
            $blank["errors"][0]["message"],
        );
    }

    public function testExistingAssignmentsChangedDuringALockWaitCannotBeApprovedFromAnEarlierSnapshot(): void
    {
        $f = new SisFixture();
        $ids = $this->library($f);
        $csv = $this->csv('1,MATH.101,Algebra,3,MATH,SCI,UG,100');
        $first = $f->imports()->preview('original', $csv, self::FULL);
        $f->imports()->commit('original', $csv, self::FULL, $first['review_token']);
        $course = $f->sql->one('sis_courses', ['code' => 'MATH.101']);
        $other = new SubjectSnapshot(EntityId::generate(), 'OTHER', 'Other fictional subject');
        new SqlSubjectRepository($f->sql)->save($other, true, true);
        $inner = new SqlCatalogImportRepository($f->sql);
        $repository = $this->createStub(CatalogImportRepository::class);
        $repository->method('inspect')->willReturnCallback($inner->inspect(...));
        $repository->method('lockRecords')->willReturnCallback(
            static function (array $values, bool $references) use ($f, $course, $other, $inner): array {
                $f->sql->update('sis_course_subjects', ['subject_id' => $other->id->value], ['id' => $course['id']]);
                return $inner->lockRecords($values, $references);
            }
        );
        $repository->method('lockReferences')->willReturnCallback($inner->lockReferences(...));
        $service = $f->imports(repository: $repository);
        $choices = [['external_id' => '1', 'action' => 'link', 'course_id' => $course['id']]];
        $review = $service->preview('different-source', $csv, self::FULL, choices: $choices);
        try {
            $service->commit('different-source', $csv, self::FULL, $review['review_token'], choices: $choices);
            self::fail('A changed course classification was trusted after a lock wait.');
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $f->sql->rows('sis_import_mappings'));
            self::assertSame(
                $ids['subject']->value,
                $f->sql->one('sis_course_subjects', ['id' => $course['id']])['subject_id']
            );
        }
    }

    public function testAddingBlankOptionalColumnsPreservesTheOriginalCoreSourceReceiptMeaning(): void
    {
        $f = new SisFixture();
        $service = $f->imports();
        $csv = $this->csv('1,MATH.101,Algebra,3,,,,');
        $review = $service->preview('source', $csv, self::CORE);
        $service->commit('source', $csv, self::CORE, $review['review_token']);
        $mappings = $f->sql->rows('sis_import_mappings');
        $review = $service->preview('source', $csv, self::FULL);
        self::assertTrue($review['ready']);
        self::assertSame('skip', $review['plan'][0]['action']);
        $service->commit('source', $csv, self::FULL, $review['review_token']);
        self::assertSame($mappings, $f->sql->rows('sis_import_mappings'));
        self::assertSame([], $f->sql->rows('sis_course_subjects'));
        self::assertSame([], $f->sql->rows('sis_course_classifications'));
    }

    public function testARequiredAuditFailureRollsBackAllClassificationAssignmentsWithTheDraftAndReceipt(): void
    {
        $f = new SisFixture();
        $this->library($f);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action): void {
            if ($action === 'catalog.import_completed') {
                throw new \RuntimeException('Fictional required audit failure.');
            }
        });
        $service = $f->imports($audit);
        $csv = $this->csv('1,MATH.101,Algebra,3,MATH,SCI,UG,100');
        $review = $service->preview('source', $csv, self::FULL);
        try {
            $service->commit('source', $csv, self::FULL, $review['review_token']);
            self::fail('An interrupted import retained part of its classification graph.');
        } catch (\RuntimeException) {
            $tables = ['sis_courses', 'sis_course_subjects', 'sis_course_classifications',
                'sis_import_mappings', 'sis_catalog_operations'];
            foreach ($tables as $table) {
                self::assertSame([], $f->sql->rows($table));
            }
        }
    }

    public function testThousandRowsShareResolvedReferencesWithoutWritingDuringPreview(): void
    {
        $f = new SisFixture();
        $this->library($f);
        $rows = [];
        for ($i = 0; $i < 1000; ++$i) {
            $rows[] = $i . ",MATH." . $i . ",Fictional algebra,3,MATH,SCI,UG,100";
        }
        $review = $f
            ->imports()
            ->preview("source", $this->csv(...$rows), self::FULL);
        self::assertTrue($review["ready"]);
        self::assertSame(1000, $review["summary"]["create"]);
        self::assertCount(4, $review["plan"][999]["references"]);
        self::assertSame([], $f->sql->rows("sis_courses"));
        self::assertSame([], $f->sql->rows("sis_course_subjects"));
    }
}
