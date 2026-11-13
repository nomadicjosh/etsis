<?php

declare(strict_types=1);

namespace Domain\Rules\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Rules\CourseEligibilityRule;
use Domain\Rules\Repository\CourseRuleRepository;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Shared curricular reference authoring, not an academic decision or student-history reader. */
final readonly class CourseRuleService
{
    public function __construct(
        private CourseRuleRepository $repository,
        private PublishedCourseReader $courses,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:rules:manage");
        $this->access->requirePermission("sis:catalog:manage");
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException("The signed-in account changed. Reload before continuing.");
        }
        return $actor;
    }
    /**
     * @param array<string,mixed> $tree
     * @return array<string,mixed>
     */
    public function save(
        ?EntityId $rule,
        ?int $expectedRevision,
        string $code,
        string $title,
        array $tree,
    ): array {
        $actor = $this->authorize();
        $code = strtoupper(trim($code));
        $title = trim($title);
        if (
            preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,79}$~D', $code) !== 1 ||
            $title === "" ||
            mb_strlen($title) > 191 ||
            !mb_check_encoding($title, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $title)
        ) {
            throw new InvalidArgumentException(
                "Use a rule code up to 80 characters and a title up to 191 characters.",
            );
        }
        if (
            ($rule === null) !== ($expectedRevision === null) ||
            ($expectedRevision !== null && ($expectedRevision < 1 || $expectedRevision >= 2000000000))
        ) {
            throw new InvalidArgumentException("Provide the expected revision when editing a course rule.");
        }
        $definition = new CourseEligibilityRule($tree);
        return $this->transactions->run(function () use (
            $actor,
            $rule,
            $expectedRevision,
            $code,
            $title,
            $definition,
        ): array {
            if ($rule !== null) {
                $latest = $this->repository->latest($rule);
                if ((int) $latest["revision"] !== $expectedRevision || $latest["code"] !== $code) {
                    throw new ConcurrentModificationException(
                        "Reload the latest revision; a rule code cannot be changed.",
                    );
                }
            }
            $references = [];
            foreach ($definition->references() as $field => $ids) {
                foreach ($ids as $id) {
                    $references[$field][$id] = $this->repository->reference($field, new EntityId($id));
                }
            }
            $id = EntityId::generate();
            $json = json_encode(
                ["tree" => $definition->rules->tree, "references" => $references],
                JSON_THROW_ON_ERROR,
            );
            if (strlen($json) > 131072) {
                throw new InvalidArgumentException("The saved course rule exceeds its reference limit.");
            }
            $this->authorize($actor);
            $this->repository->append([
                "id" => $id->value,
                "rule_id" => $rule === null ? $id->value : $rule->value,
                "code" => $code,
                "title" => $title,
                "revision" => ($expectedRevision ?? 0) + 1,
                "definition_json" => $json,
                "definition_hash" => hash("sha256", $json),
                "created_at" => $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z"),
                "created_by" => $actor->value,
            ]);
            $this->audit->record($actor, "course_rule.revision_saved", $id);
            $this->authorize($actor);
            return $this->get($id);
        });
    }
    /** @return array<string,mixed> */
    public function get(EntityId $version): array
    {
        $actor = $this->authorize();
        $row = $this->repository->revision($version);
        $json = $row["definition_json"];
        if (strlen($json) > 131072 || !hash_equals($row["definition_hash"], hash("sha256", $json))) {
            throw new InvalidArgumentException("Saved course-rule contents could not be verified.");
        }
        $definition = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($definition) ||
            count($definition) !== 2 ||
            !is_array($definition["tree"] ?? null) ||
            !is_array($definition["references"] ?? null)
        ) {
            throw new InvalidArgumentException("Saved course-rule contents could not be verified.");
        }
        new CourseEligibilityRule($definition["tree"]);
        $this->authorize($actor);
        return array_intersect_key(
            $row,
            array_flip(["id", "rule_id", "code", "title", "created_at", "definition_hash"]),
        ) + ["revision" => (int) $row["revision"], "definition" => $definition];
    }
    /** @return array<string,mixed> */
    public function page(string $search = "", ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        $search = trim($search);
        if (strlen($search) > 80 || preg_match('/[\x00-\x1F\x7F]/', $search)) {
            throw new InvalidArgumentException("Use a rule-code search up to 80 characters.");
        }
        $roots = $this->repository->roots(strtoupper($search), $after);
        $items = [];
        foreach (array_slice($roots, 0, 20) as $root) {
            $item = $this->repository->latest(new EntityId($root["id"]));
            $item["revision"] = (int) $item["revision"];
            $items[] = $item;
        }
        $this->authorize($actor);
        return ["items" => $items, "next_after" => count($roots) > 20 ? $roots[19]["id"] : null];
    }
    /**
     * @param array<string,mixed> $tree
     * @param list<array{course_id:string,version_id:string}> $publications Explicit versions; never a catalog scan.
     * @return array<string,mixed>
     */
    public function preview(array $tree, array $publications): array
    {
        $actor = $this->authorize();
        $rule = new CourseEligibilityRule($tree);
        if (count($publications) < 1 || count($publications) > 20) {
            throw new InvalidArgumentException("Select 1–20 published course versions to preview.");
        }
        $budget = new HistoryReadBudget();
        $items = [];
        $seen = [];
        foreach ($publications as $reference) {
            $key = $reference["course_id"] . ":" . $reference["version_id"];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Select each publication only once.");
            }
            $seen[$key] = true;
            $course = new EntityId($reference["course_id"]);
            $publication = $this->courses->version($course, new EntityId($reference["version_id"]), $budget);
            $decision = $rule->decision($course, $publication->definition);
            $items[] = $reference + [
                "code" => $publication->definition->code,
                "title" => $publication->definition->title,
                "outcome" => $decision === null ? "unknown" : ($decision ? "matches" : "does_not_match"),
            ];
        }
        $this->authorize($actor);
        return ["items" => $items];
    }
    /** @return array<string,mixed> */
    public function versions(EntityId $rule, ?int $before = null): array
    {
        $actor = $this->authorize();
        if ($before !== null && ($before < 1 || $before > 2000000000)) {
            throw new InvalidArgumentException("Use a valid revision cursor.");
        }
        $rows = $this->repository->versions($rule, $before);
        $items = array_slice($rows, 0, 20);
        foreach ($items as &$item) {
            $item["revision"] = (int) $item["revision"];
        }
        unset($item);
        $this->authorize($actor);
        return ["items" => $items, "next_before" => count($rows) > 20 ? (int) $rows[19]["revision"] : null];
    }
}
