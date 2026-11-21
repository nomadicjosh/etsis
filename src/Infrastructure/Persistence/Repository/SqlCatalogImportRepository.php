<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Import\ValueObject\CatalogImportRow;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCatalogImportRepository implements CatalogImportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    private function table(CatalogImportTarget $target): string
    {
        return match ($target) {
            CatalogImportTarget::Course => "sis_courses",
            CatalogImportTarget::Program => "sis_programs",
            CatalogImportTarget::Subject => "sis_subjects",
            default => "sis_catalog_classifications",
        };
    }

    private function mappingTable(CatalogImportTarget $target): string
    {
        return match ($target) {
            CatalogImportTarget::Course => "sis_import_mappings",
            CatalogImportTarget::Program => "sis_program_import_mappings",
            CatalogImportTarget::Subject => "sis_subject_import_mappings",
            default => "sis_class_import_mappings",
        };
    }

    public function inspect(string $source, array $rows): array
    {
        $target = $rows[0]->target ?? CatalogImportTarget::Course;
        foreach ($rows as $row) {
            if (!($row instanceof CatalogImportRow) || $row->target !== $target) {
                throw new \InvalidArgumentException("Keep one catalog destination per import.");
            }
        }
        $q = $this->sql->quote(...);
        $mappings = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $ids = array_map(static fn(CatalogImportRow $r): string => strtolower($r->externalId), $chunk);
            $found = $this->sql->fetch(
                "SELECT * FROM " .
                    $q($this->mappingTable($target)) .
                    " WHERE " .
                    $q("source") .
                    " = ? AND LOWER(" .
                    $q("external_id") .
                    ") IN (" .
                    implode(",", array_fill(0, count($ids), "?")) .
                    ")" .
                    ($target->classification() !== null ? " AND " . $q("kind") . " = ?" : ""),
                [$source, ...$ids, ...$target->classification() !== null ? [$target->value] : []],
                count($ids) * 2 + 1,
            );
            if (count($found) > count($ids) * 2) {
                throw new ValidationException([
                    [
                        "field" => "file",
                        "message" =>
                            "Too many source identity matches. Resolve ambiguous IDs before importing.",
                    ],
                ]);
            }
            foreach ($found as $row) {
                $key = "external:" . strtolower($row["external_id"]);
                $mappings[$key] = isset($mappings[$key]) ? ["ambiguous" => true] : $row;
            }
        }
        $codes = [];
        $referenceCodes = [];
        foreach ($rows as $row) {
            if (!isset($mappings["external:" . strtolower($row->externalId)])) {
                $codes[] = mb_strtolower($row->code);
                foreach ($row->references as $kind => $code) {
                    $referenceCodes[$kind][] = $code;
                }
            }
        }
        $records = [];
        foreach (array_chunk(array_values(array_unique($codes)), 200) as $chunk) {
            $limit = count($chunk) * 2;
            if ($target === CatalogImportTarget::Course) {
                $select =
                'SELECT d.' . $q('course_number') . ', c.' .
                $q("id") .
                ", c." .
                $q("code") .
                ", c." .
                $q("title") .
                ", c." .
                $q("credits_milli") .
                ", c." .
                $q("active") .
                ", s." .
                $q("subject_id") .
                ", k." .
                $q("department_id") .
                ", k." .
                $q("academic_level_id") .
                ", k." .
                $q("course_level_id") .
                " FROM " .
                $q("sis_courses") .
                " c LEFT JOIN " .
                $q("sis_course_subjects") .
                " s ON s." .
                $q("id") .
                " = c." .
                $q("id") .
                " LEFT JOIN " .
                $q("sis_course_classifications") .
                " k ON k." .
                $q("id") .
                " = c." .
                $q("id") . ' LEFT JOIN ' . $q('sis_course_descriptions') . ' d ON d.' .
                $q('course_id') . ' = c.' . $q('id');
            } elseif ($target->isReference()) {
                $select = "SELECT c.* FROM " . $q($this->table($target)) . " c";
            } else {
                $columns = ["c." . $q("id"), "c." . $q("code"), "c." . $q("title"), "d." . $q("description")];
                foreach (array_keys($target->references()) as $kind) {
                    $columns[] = "d." . $q($kind . "_id");
                }
                $select =
                "SELECT " .
                implode(", ", $columns) .
                " FROM " .
                $q("sis_programs") .
                " c LEFT JOIN " .
                $q("sis_program_details") .
                " d ON d." .
                $q("id") .
                " = c." .
                $q("id");
            }
            $found = $this->sql->fetch(
                $select .
                    " WHERE LOWER(c." .
                    $q("code") .
                    ") IN (" .
                    implode(",", array_fill(0, count($chunk), "?")) .
                    ")" .
                    ($target->classification() !== null ? " AND c." . $q("kind") . " = ?" : ""),
                [...$chunk, ...$target->classification() !== null ? [$target->value] : []],
                $limit + 1,
            );
            if (count($found) > $limit) {
                throw new ValidationException([
                    [
                        "field" => "file",
                        "message" => "Too many catalog matches. Reduce the file or resolve duplicate codes.",
                    ],
                ]);
            }
            foreach ($found as $record) {
                $records[mb_strtolower($record["code"])][] = $record;
            }
        }
        return [
            "mappings" => $mappings,
            "records" => $records,
            "references" => $this->references($referenceCodes),
        ];
    }

    public function lockRecords(
        array $ids,
        bool $withReferences = false,
        CatalogImportTarget $target = CatalogImportTarget::Course,
    ): array {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->sql->current($this->table($target), $id);
            if ($row === null) {
                throw new ConcurrentModificationException(
                    "A matched catalog record was removed. Review the import again.",
                );
            }
            if ($target->classification() !== null && ($row["kind"] ?? null) !== $target->value) {
                throw new ConcurrentModificationException("The reference category changed. Review again.");
            }
            $record = array_intersect_key(
                $row,
                array_flip(["id", "code", "title", "credits_milli", "active", "kind"]),
            );
            if ($target === CatalogImportTarget::Course) {
                $text = $this->sql->current('sis_course_descriptions', $id, 'course_id');
                $record['course_number'] = $text['course_number'] ?? null;
            }
            if ($target === CatalogImportTarget::Program) {
                $details = $this->sql->current("sis_program_details", $id);
                $record["description"] = $details["description"] ?? null;
                foreach (array_keys($target->references()) as $kind) {
                    $record[$kind . "_id"] = $details[$kind . "_id"] ?? null;
                }
            } elseif ($target === CatalogImportTarget::Course && $withReferences) {
                $subject = $this->sql->current("sis_course_subjects", $id);
                $classes = $this->sql->current("sis_course_classifications", $id);
                $record["subject_id"] = $subject["subject_id"] ?? null;
                foreach (ClassificationKind::courseKinds() as $kind) {
                    $record[$kind->value . "_id"] = $classes[$kind->value . "_id"] ?? null;
                }
            }
            $rows[] = $record;
        }
        return $rows;
    }

    public function lockReferences(array $references): array
    {
        $subjects = [];
        $classifications = [];
        foreach ($references as $reference) {
            if (
                !isset(
                    (CatalogImportTarget::Course->references() + CatalogImportTarget::Program->references())[
                        $reference["kind"]
                    ],
                )
            ) {
                throw new \InvalidArgumentException("Unknown catalog classification.");
            }
            if ($reference["kind"] === "subject") {
                $subjects[$reference["id"]] = true;
            } else {
                $classifications[$reference["id"]] = true;
            }
        }
        ksort($subjects, SORT_STRING);
        ksort($classifications, SORT_STRING);
        $locked = [];
        // Match publication's course -> subject -> classification order across the complete batch.
        $tables = ["sis_subjects" => $subjects, "sis_catalog_classifications" => $classifications];
        foreach ($tables as $table => $ids) {
            foreach (array_keys($ids) as $id) {
                $row = $this->sql->current($table, $id);
                if ($row === null) {
                    throw new ConcurrentModificationException(
                        "A catalog reference was removed. Review the import again.",
                    );
                }
                if ($table === "sis_subjects") {
                    $row["kind"] = "subject";
                }
                $locked[] = $row;
            }
        }
        return $locked;
    }

    /** @param array<string,list<string>> $codes
     * @return array<string,list<array<string,mixed>>> */
    private function references(array $codes): array
    {
        $q = $this->sql->quote(...);
        $references = [];
        foreach ($codes as $kind => $values) {
            $table = $kind === "subject" ? "sis_subjects" : "sis_catalog_classifications";
            foreach (array_chunk(array_values(array_unique($values)), 200) as $chunk) {
                $found = $this->sql->fetch(
                    "SELECT " .
                        $q("id") .
                        ", " .
                        $q("code") .
                        ", " .
                        $q("title") .
                        ", " .
                        $q("active") .
                        " FROM " .
                        $q($table) .
                        " WHERE UPPER(" .
                        $q("code") .
                        ") IN (" .
                        implode(",", array_fill(0, count($chunk), "?")) .
                        ")" .
                        ($kind === "subject" ? "" : " AND " . $q("kind") . " = ?"),
                    $kind === "subject" ? $chunk : [...$chunk, $kind],
                    count($chunk) * 2 + 1,
                );
                if (count($found) > count($chunk) * 2) {
                    throw new ValidationException([
                        [
                            "field" => "file",
                            "message" =>
                                "Too many classification matches. Resolve ambiguous codes before importing.",
                        ],
                    ]);
                }
                foreach ($found as $reference) {
                    $reference["kind"] = $kind;
                    $references[$kind . ":" . strtoupper($reference["code"])][] = $reference;
                }
            }
        }
        return $references;
    }

    public function create(string $source, CatalogImportRow $row, array $references): EntityId
    {
        if (
            array_diff_key($row->references, $references) !== [] ||
            array_diff_key($references, $row->references) !== []
        ) {
            throw new \InvalidArgumentException(
                "Every requested classification requires a reviewed reference.",
            );
        }
        if ($row->target->isReference()) {
            $id = EntityId::generate();
            $value = new ClassificationSnapshot($id, $row->code, $row->title);
            if ($row->target === CatalogImportTarget::Subject) {
                new SqlSubjectRepository($this->sql)->save(
                    new SubjectSnapshot($id, $value->code, $value->title),
                    true,
                    true,
                );
            } else {
                new SqlClassificationRepository($this->sql)->save(
                    $row->target->classification(),
                    $value,
                    true,
                    true,
                );
            }
            $this->link($source, $row, $id);
            return $id;
        }
        if ($row->target === CatalogImportTarget::Program) {
            $id = EntityId::generate();
            $this->sql->insert("sis_programs", [
                "id" => $id->value,
                "code" => $row->code,
                "title" => $row->title,
            ]);
            $choices = [];
            foreach (array_keys($row->target->references()) as $kind) {
                $choices[$kind] = isset($references[$kind])
                ? ClassificationSnapshot::fromArray($references[$kind])->id
                : null;
            }
            new SqlProgramDefinitionRepository($this->sql)->saveDraft($id, $row->description, $choices);
            $this->link($source, $row, $id);
            return $id;
        }
        $course = $row->draft();
        $this->sql->insert("sis_courses", [
            "id" => $course->id->value,
            "code" => $course->code,
            "title" => $course->title,
            "credits_milli" => $course->creditsMilli,
            "active" => 0,
        ]);
        if ($row->numberMapped) {
            new \Infrastructure\Persistence\SqlCourseText($this->sql)->save(
                $course->id,
                new \Domain\Catalog\ValueObject\CourseText(courseNumber: $row->courseNumber)
            );
        }
        if (isset($references["subject"])) {
            new SqlSubjectRepository($this->sql)->assign(
                $course->id,
                SubjectSnapshot::fromArray($references["subject"])->id,
            );
        }
        $classes = [];
        foreach (ClassificationKind::courseKinds() as $kind) {
            $classes[$kind->value] = $references[$kind->value] ?? null;
        }
        if (array_filter($classes) !== []) {
            new SqlClassificationRepository($this->sql)->assign(
                $course->id,
                CourseClassifications::fromArray($classes),
            );
        }
        $this->link($source, $row, $course->id);
        return $course->id;
    }

    public function link(string $source, CatalogImportRow $row, EntityId $course): void
    {
        $this->sql->insert(
            $this->mappingTable($row->target),
            [
                "id" => EntityId::generate()->value,
                "source" => $source,
                "external_id" => $row->externalId,
                "content_hash" => $row->fingerprint(),
                $row->target->idField() => $course->value,
            ] + ($row->target->classification() !== null ? ["kind" => $row->target->value] : []),
        );
    }
}
