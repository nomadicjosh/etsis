<?php

declare(strict_types=1);

namespace Domain\AcademicCalendar\Service;

use DateTimeZone;
use Domain\AcademicCalendar\Repository\AcademicCalendarRepository;
use Domain\AcademicCalendar\ValueObject\AcademicTerm;
use Domain\AcademicCalendar\ValueObject\TermType;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class AcademicCalendarService
{
    public function __construct(
        private AcademicCalendarRepository $calendar,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    private function grant(): EntityId
    {
        return $this->access->requirePermission("sis:catalog:manage");
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash("sha256", json_encode($value, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function previewType(TermType $type, int $start): array
    {
        $this->grant();
        return $type->generate($start) + ["academic_year" => $start . "-" . ($start + 1)];
    }

    /** @return array<string, mixed> */
    public function types(?EntityId $after = null): array
    {
        $this->grant();
        return $this->page($this->calendar->types($after));
    }

    /** @return array<string, mixed> */
    public function saveType(TermType $type, int $version): array
    {
        $actor = $this->grant();
        return $this->transactions->run(function () use ($type, $version, $actor): array {
            $this->calendar->lockCalendar();
            try {
                $old = $this->calendar->type($type->id);
            } catch (RecordNotFoundException) {
                $old = null;
            }
            if ($old !== null && $old->version === $version + 1 && $old->describe() === $type->describe()) {
                return $old->describe();
            }
            if (($old->version ?? -1) !== $version) {
                throw new ConcurrentModificationException("Reload the term type before saving.");
            }
            $this->calendar->saveType($type, $version);
            $this->calendar->touch();
            $this->audit->record(
                $actor,
                $version < 0 ? "calendar.term_type_created" : "calendar.term_type_updated",
                $type->id,
            );
            return $this->calendar->type($type->id)->describe();
        });
    }

    /** @return array<string, mixed> */
    public function years(?EntityId $after = null, string $search = ""): array
    {
        $this->grant();
        if ($search !== "") {
            $row =
            preg_match('/^[12][0-9]{3}(?:-[12][0-9]{3})?$/D', $search) === 1
            ? $this->calendar->yearByStart((int) substr($search, 0, 4))
            : null;
            return ["items" => $row === null ? [] : [$row], "next_after" => null];
        }
        return $this->page($this->calendar->years($after));
    }

    /** @return array<string, mixed> */
    public function review(int $start): array
    {
        $this->grant();
        $id = EntityId::generate();
        return $this->transactions->run(function () use ($id, $start): array {
            $this->calendar->lockCalendar();
            $context = $this->propose($id, $start, []);
            return $context + ["operation_id" => EntityId::generate()->value, "revision" => $this->hash($context)];
        });
    }

    /** @param array<string, string> $references
     * @return array<string, mixed>
     */
    private function propose(EntityId $id, int $start, array $references): array
    {
        if ($start < 1000 || $start > 2998) {
            throw new ValidationException([
                ["field" => "start_year", "message" => "Enter a four-digit start year from 1000 to 2998."],
            ]);
        }
        if ($this->calendar->yearByStart($start) !== null) {
            throw new ConcurrentModificationException("This academic year already exists. Open its terms instead.");
        }
        $types = $this->calendar->types(null, true);
        if (count($types) < 1 || count($types) > 100) {
            throw new ValidationException([
                [
                    "field" => "term_types",
                    "message" => "Set up 1–100 active term types before creating an academic year.",
                ],
            ]);
        }
        if (
            $references !== [] &&
            (count($references) !== count($types) ||
                array_diff(array_keys($references), array_column($types, "id")) !== [])
        ) {
            throw new ConcurrentModificationException("The active term types changed. Review again.");
        }
        $terms = [];
        $codes = [];
        $ids = [];
        foreach ($types as $row) {
            $type = $this->calendar->type(new EntityId($row["id"]));
            $term = $type->generate($start);
            if (isset($codes[$term["code"]])) {
                throw new ValidationException([
                    ["field" => "code_pattern", "message" => "Active term types generate duplicate term codes."],
                ]);
            }
            $codes[$term["code"]] = true;
            $existing = $this->calendar->termByCode($term["code"]);
            if ($existing !== null) {
                $this->checkExistingTerm($type, $term, $existing, $start);
            }
            if ($existing !== null && isset($references[$row["id"]]) && $references[$row["id"]] !== $existing["id"]) {
                throw new ConcurrentModificationException("The reviewed existing term changed. Review again.");
            }
            $termId = $existing["id"] ?? ($references[$row["id"]] ?? EntityId::generate()->value);
            new EntityId($termId);
            if (isset($ids[$termId])) {
                throw new ValidationException([["field" => "terms", "message" => "Use distinct term references."]]);
            }
            $ids[$termId] = true;
            $terms[] = $term + ["id" => $termId, "adopt_existing" => $existing !== null];
        }
        $excluded = array_column(array_filter($terms, static fn(array $t): bool => $t["adopt_existing"]), "id");
        foreach ($terms as $i => $term) {
            if ($term["kind"] !== "standard") {
                continue;
            }
            foreach (array_slice($terms, $i + 1) as $other) {
                if (
                    $other["kind"] === "standard" &&
                    $term["starts_on"] <= $other["ends_on"] &&
                    $term["ends_on"] >= $other["starts_on"]
                ) {
                    throw new ValidationException([
                        [
                            "field" => "terms",
                            "message" => "Standard terms cannot overlap, including their first and last days.",
                        ],
                    ]);
                }
            }
            $this->noOverlap(
                new AcademicTerm(
                    $term["code"],
                    $term["name"],
                    $term["kind"],
                    $term["starts_on"],
                    $term["ends_on"],
                    $term["add_drop_ends_on"],
                ),
                null,
                $excluded,
            );
        }
        usort(
            $terms,
            static fn(array $a, array $b): int => [$a["starts_on"], $a["ends_on"], $a["code"]] <=> [
                $b["starts_on"],
                $b["ends_on"],
                $b["code"],
            ],
        );
        $starts = array_column($terms, "starts_on");
        $ends = array_column($terms, "ends_on");
        if ($starts === [] || $ends === []) {
            throw new \LogicException("Generation requires at least one active term type.");
        }
        return [
            "id" => $id->value,
            "start_year" => $start,
            "end_year" => $start + 1,
            "code" => $start . "-" . ($start + 1),
            "starts_on" => min($starts),
            "ends_on" => max($ends),
            "calendar_version" => $this->calendar->revision(),
            "terms" => $terms,
        ];
    }

    /**
     * @param array<string, mixed> $generated
     * @param array<string, mixed> $existing
     */
    private function checkExistingTerm(TermType $type, array $generated, array $existing, int $start): void
    {
        $context = sprintf(
            "Term type %s generates code %s for academic year %d-%d.",
            $type->code,
            $generated["code"],
            $start,
            $start + 1,
        );
        if ($existing["academic_year_id"] !== null) {
            $year = $this->calendar->year(new EntityId($existing["academic_year_id"]));
            throw new ValidationException([
                [
                    "field" => "terms",
                    "message" =>
                        $context .
                        " That code already belongs to academic year " .
                        $year["code"] .
                        ". A linked term cannot be reused for another academic year. Review the Term code template " .
                        "in Term types; use a year placeholder such as {{START_YEAR_SHORT}}/FA for Fall " .
                        "or {{END_YEAR_SHORT}}/SP for Spring in the following year.",
                ],
            ]);
        }
        $differences = [];
        $dateLabels = ["starts_on" => "first day", "ends_on" => "last day", "add_drop_ends_on" => "drop/add deadline"];
        foreach ($dateLabels as $field => $label) {
            if ($existing[$field] !== $generated[$field]) {
                $differences[] = $label . ": existing " . $existing[$field] . ", generated " . $generated[$field];
            }
        }
        if ($differences !== []) {
            $fixedCodeHint = str_contains($type->codePattern, "{{")
            ? ""
            : " This template uses the same code every year. " .
            "Include a year placeholder such as {{START_YEAR_SHORT}}/FA.";
            throw new ValidationException([
                [
                    "field" => "terms",
                    "message" =>
                        $context .
                        " An unlinked term already uses that code with different dates (" .
                        implode("; ", $differences) .
                        "). Review that record under Terms, or adjust this template’s " .
                        "code and date settings under Term types. Generation can link an existing term only when " .
                        "all dates match. Existing dates have not been changed." .
                        $fixedCodeHint,
                ],
            ]);
        }
    }

    /** @param array<string, string> $references
     * @return array<string, mixed>
     */
    public function create(EntityId $id, EntityId $operation, int $start, array $references, string $revision): array
    {
        $actor = $this->grant();
        if ($references === []) {
            throw new ValidationException([["field" => "terms", "message" => "Confirm all reviewed term references."]]);
        }
        ksort($references);
        $hash = $this->hash([
            "id" => $id->value,
            "start_year" => $start,
            "terms" => $references,
            "revision" => $revision,
        ]);
        if (($old = $this->calendar->operation($operation)) !== null) {
            return $this->replay($old, $id, $actor, $hash);
        }
        return $this->transactions->run(function () use (
            $id,
            $operation,
            $start,
            $references,
            $revision,
            $actor,
            $hash,
        ): array {
            $this->calendar->lockCalendar();
            if (($old = $this->calendar->operation($operation)) !== null) {
                return $this->replay($old, $id, $actor, $hash);
            }
            $context = $this->propose($id, $start, $references);
            if (!hash_equals($revision, $this->hash($context))) {
                throw new ConcurrentModificationException("The calendar changed. Review term generation again.");
            }
            $this->calendar->addYear(
                array_intersect_key(
                    $context,
                    array_flip(["id", "start_year", "end_year", "code", "starts_on", "ends_on"]),
                ),
            );
            foreach ($context["terms"] as $term) {
                $this->calendar->saveTerm(new EntityId($term["id"]), $id, $term, !$term["adopt_existing"]);
                $this->audit->record(
                    $actor,
                    $term["adopt_existing"] ? "calendar.term_adopted" : "calendar.term_generated",
                    new EntityId($term["id"]),
                );
            }
            $this->calendar->touch();
            $this->audit->record($actor, "calendar.academic_year_created", $id);
            $this->calendar->record(
                $operation,
                $id,
                $actor,
                $hash,
                $this->cipher->seal(json_encode($context, JSON_THROW_ON_ERROR)),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            return $context;
        });
    }

    /** @return array<string, mixed> */
    public function receipt(EntityId $id, EntityId $operation): array
    {
        $actor = $this->grant();
        return $this->replay(
            $this->calendar->operation($operation) ??
                throw new RecordNotFoundException("Academic-year receipt not found."),
            $id,
            $actor,
            null,
        );
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(array $row, EntityId $id, EntityId $actor, ?string $hash): array
    {
        if ($row["year_id"] !== $id->value || $row["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        if ($hash !== null && !hash_equals($row["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This reference belongs to another academic-year creation.");
        }
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function term(EntityId $id): array
    {
        $this->grant();
        $row = $this->calendar->term($id);
        return $row + ["revision" => $this->hash($row)];
    }

    /** @return array<string, mixed> */
    public function saveTerm(EntityId $id, ?EntityId $year, AcademicTerm $term, ?string $revision): array
    {
        $actor = $this->grant();
        return $this->transactions->run(function () use ($id, $year, $term, $revision, $actor): array {
            $this->calendar->lockCalendar();
            if ($year === null && $revision === null) {
                throw new ValidationException([
                    ["field" => "academic_year_id", "message" => "Choose an academic year for the new term."],
                ]);
            }
            $academic = $year === null ? null : $this->calendar->year($year);
            if (
                $academic !== null &&
                ($term->startsOn < $academic["start_year"] . "-01-01" ||
                    $term->endsOn > $academic["end_year"] . "-12-31")
            ) {
                throw new ValidationException([
                    [
                        "field" => "starts_on",
                        "message" => "Term dates must use the academic year’s start or end calendar year.",
                    ],
                ]);
            }
            if ($revision !== null) {
                $current = $this->calendar->term($id);
                if (!hash_equals($revision, $this->hash($current))) {
                    if (
                        $current["academic_year_id"] === $year?->value &&
                        array_intersect_key($current, $term->describe()) == $term->describe()
                    ) {
                        return $current;
                    }
                    throw new ConcurrentModificationException("The term changed. Reload it.");
                }
                if ($current["academic_year_id"] !== null && $current["academic_year_id"] !== $year?->value) {
                    throw new ValidationException([
                        [
                            "field" => "academic_year_id",
                            "message" => "An assigned academic year stays fixed to protect matriculation history.",
                        ],
                    ]);
                }
            }
            if ($revision === null) {
                try {
                    $same = $this->calendar->term($id);
                } catch (RecordNotFoundException) {
                    $same = null;
                }
                if ($same !== null) {
                    if (
                        $same["academic_year_id"] === $year->value &&
                        array_intersect_key($same, $term->describe()) == $term->describe()
                    ) {
                        return $same;
                    }
                    throw new ConcurrentModificationException("This term reference is already in use.");
                }
            }
            $existing = $this->calendar->termByCode($term->code);
            if ($existing !== null && $existing["id"] !== $id->value) {
                throw new ValidationException([["field" => "code", "message" => "This term code already exists."]]);
            }
            $this->noOverlap($term, $revision === null ? null : $id);
            $this->calendar->saveTerm($id, $year, $term->describe(), $revision === null);
            $this->calendar->touch();
            $this->audit->record($actor, $revision === null ? "calendar.term_created" : "calendar.term_updated", $id);
            return $this->calendar->term($id);
        });
    }

    /** @param list<string> $excluding */
    private function noOverlap(AcademicTerm $term, ?EntityId $except, array $excluding = []): void
    {
        if (
            $term->kind === "standard" &&
            $this->calendar->overlaps($term->startsOn, $term->endsOn, $except, $excluding) !== []
        ) {
            throw new ValidationException([
                [
                    "field" => "starts_on",
                    "message" =>
                        "This standard term overlaps a standard or unclassified existing term. Review the Terms entry.",
                ],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function page(array $rows): array
    {
        return ["items" => array_slice($rows, 0, 50), "next_after" => count($rows) > 50 ? $rows[49]["id"] : null];
    }
}
