<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\Repository\OfferingTemplateRepository;
use Domain\Catalog\ValueObject\OfferingRecipe;
use Domain\Catalog\ValueObject\SectionBlueprint;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\DeliveryMode;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class OfferingTemplateService
{
    public function __construct(
        private OfferingTemplateRepository $templates,
        private CatalogRevisionRepository $catalog,
        private CatalogWorkflowRepository $operations,
        private RegistrationRepository $registration,
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
    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(DATE_ATOM);
    }
    /** @param array<string, mixed> $data */
    private function hash(array $data): string
    {
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }
    private function name(string $name): string
    {
        if (trim($name) === "" || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ValidationException([
                ["field" => "name", "message" => "Enter a template name of up to 120 characters."],
            ]);
        }
        return trim($name);
    }
    /** @return array<string, mixed> */
    private function current(EntityId $id): array
    {
        return $this->templates->find($id) ?? throw new RecordNotFoundException("Offering template not found.");
    }
    /** @param array<string, mixed> $row */
    private function recipe(array $row): OfferingRecipe
    {
        $rows = json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
        return new OfferingRecipe(
            ...array_map(
                static fn(array $s): SectionBlueprint => new SectionBlueprint(
                    new EntityId($s["course_id"]),
                    $s["code"],
                    $s["capacity"],
                    $s["faculty_user_id"] === null ? null : new EntityId($s["faculty_user_id"]),
                    new Money($s["fee_minor"], $s["currency"]),
                    DeliveryMode::parse($s["delivery_mode"]),
                    $s["web_registration"],
                ),
                $rows,
            ),
        );
    }
    private function locks(OfferingRecipe $recipe, ?EntityId $id = null): void
    {
        if ($id !== null) {
            $this->templates->lock($id);
        }
        $courses = array_unique(array_column($recipe->describe(), "course_id"));
        sort($courses, SORT_STRING);
        foreach ($courses as $course) {
            $this->catalog->lock("courses", new EntityId($course));
        }
        $faculty = array_values(array_unique(array_filter(array_column($recipe->describe(), "faculty_user_id"))));
        sort($faculty, SORT_STRING);
        foreach ($faculty as $user) {
            $this->templates->lockFaculty(new EntityId($user));
        }
    }
    /** @return list<array<string, mixed>> */
    private function details(OfferingRecipe $recipe): array
    {
        return array_map(function (SectionBlueprint $section): array {
            $warnings = [];
            $code = $title = null;
            $active = false;
            $facultyName = null;
            try {
                $course = $this->registration->course($section->course);
                $code = $course->code;
                $title = $course->title;
                $active = $course->active;
                if (!$active) {
                    $warnings[] = "Course is inactive; select an active course before saving an offering.";
                }
            } catch (RecordNotFoundException) {
                $warnings[] = "Course is unavailable; replace it before saving an offering.";
            }
            if ($section->faculty !== null) {
                try {
                    $facultyName = $this->operations->faculty($section->faculty)["name"];
                } catch (RecordNotFoundException) {
                    $warnings[] = "Faculty account is unavailable; clear or replace it.";
                }
            }
            return $section->describe() + [
                "course_code" => $code,
                "course_title" => $title,
                "course_active" => $active,
                "faculty_name" => $facultyName,
                "warnings" => $warnings,
            ];
        }, $recipe->sections);
    }
    /** @return array<string, mixed> */
    public function review(?EntityId $id, int $version, string $name, OfferingRecipe $recipe): array
    {
        $this->grant();
        $name = $this->name($name);
        if (($id === null && $version !== -1) || ($id !== null && $version < 0)) {
            throw new ValidationException([["field" => "version", "message" => "Use the current template version."]]);
        }
        $template = $id ?? EntityId::generate();
        return $this->transactions->run(function () use ($id, $template, $version, $name, $recipe): array {
            $this->locks($recipe, $id);
            $context = $this->context($template, $version, $name, $recipe);
            return $context + ["operation_id" => EntityId::generate()->value, "revision" => $this->hash($context)];
        });
    }
    /** @return array<string, mixed> */
    private function context(EntityId $id, int $version, string $name, OfferingRecipe $recipe): array
    {
        $row = $this->templates->find($id);
        if (($row["version"] ?? -1) !== $version) {
            throw new ConcurrentModificationException("The template changed. Review it again.");
        }
        return [
            "id" => $id->value,
            "version" => $version,
            "name" => $name,
            "active" => $row["active"] ?? true,
            "sections" => $this->details($recipe),
        ];
    }
    /** @return array<string, mixed> */
    public function save(
        EntityId $id,
        EntityId $operation,
        int $version,
        string $name,
        OfferingRecipe $recipe,
        string $revision,
    ): array {
        $actor = $this->grant();
        $name = $this->name($name);
        $hash = $this->hash([
            "id" => $id->value,
            "version" => $version,
            "name" => $name,
            "sections" => $recipe->describe(),
            "revision" => $revision,
        ]);
        if (($old = $this->templates->operation($operation)) !== null) {
            return $this->replay($old, $id, $actor, $hash);
        }
        return $this->transactions->run(function () use (
            $id,
            $operation,
            $version,
            $name,
            $recipe,
            $revision,
            $actor,
            $hash,
        ): array {
            $this->locks($recipe, $version < 0 ? null : $id);
            if (($old = $this->templates->operation($operation)) !== null) {
                return $this->replay($old, $id, $actor, $hash);
            }
            $context = $this->context($id, $version, $name, $recipe);
            if (!hash_equals($this->hash($context), $revision)) {
                throw new ConcurrentModificationException("The catalog or template changed. Review it again.");
            }
            $before = $this->templates->find($id);
            $this->templates->save(
                $id,
                $actor,
                $version,
                $name,
                $context["active"],
                count($recipe->sections),
                $this->cipher->seal(json_encode($recipe->describe(), JSON_THROW_ON_ERROR)),
                $this->now(),
            );
            return $this->record(
                $id,
                $operation,
                $actor,
                $version + 1,
                $hash,
                $version < 0 ? "created" : "revised",
                "",
                $before,
            );
        });
    }
    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $this->grant();
        $row = $this->current($id);
        return array_intersect_key(
            $row,
            array_flip(["id", "name", "active", "version", "section_count", "created_at", "updated_at"]),
        ) + ["sections" => $this->details($this->recipe($row))];
    }
    /** @return array<string, mixed> */
    public function page(?bool $active, string $search = "", ?EntityId $after = null): array
    {
        $this->grant();
        if (mb_strlen($search) > 80 || preg_match('/[\x00-\x1F\x7F]/', $search)) {
            throw new ValidationException([["field" => "search", "message" => "Search with up to 80 characters."]]);
        }
        if ($after !== null) {
            $this->current($after);
        }
        $rows = $this->templates->page($active, trim($search), $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row["active"] = (bool) $row["active"];
            $row["version"] = (int) $row["version"];
            $row["section_count"] = (int) $row["section_count"];
        }
        unset($row);
        return ["items" => $rows, "next_after" => $more ? $rows[49]["id"] : null];
    }
    /** @return array<string, mixed> */
    public function prepare(EntityId $id, int $version, EntityId $term): array
    {
        $this->grant();
        return $this->transactions->run(function () use ($id, $version, $term): array {
            $this->templates->lock($id);
            $this->catalog->lock("terms", $term);
            $row = $this->current($id);
            if (!$row["active"] || $row["version"] !== $version) {
                throw new ConcurrentModificationException("Choose a current active template.");
            }
            $recipe = $this->recipe($row);
            $calendar = $this->catalog->get("terms", $term);
            return [
                "template_id" => $id->value,
                "template_name" => $row["name"],
                "template_version" => $version,
                "term" => $calendar,
                "sections" => $recipe->instantiate($term)->describe(),
                "defaults" => $this->details($recipe),
            ];
        });
    }
    /** @return array<string, mixed> */
    public function status(EntityId $id, EntityId $operation, int $version, bool $active, string $note): array
    {
        $actor = $this->grant();
        if (trim($note) === "" || mb_strlen($note) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)) {
            throw new ValidationException([
                ["field" => "note", "message" => "Explain this template status change in up to 2000 characters."],
            ]);
        }
        $note = trim($note);
        $hash = $this->hash(["id" => $id->value, "version" => $version, "active" => $active, "note" => $note]);
        if (($old = $this->templates->operation($operation)) !== null) {
            return $this->replay($old, $id, $actor, $hash);
        }
        return $this->transactions->run(function () use (
            $id,
            $operation,
            $version,
            $active,
            $note,
            $actor,
            $hash,
        ): array {
            $this->templates->lock($id);
            if (($old = $this->templates->operation($operation)) !== null) {
                return $this->replay($old, $id, $actor, $hash);
            }
            $before = $this->current($id);
            if ($before["version"] !== $version || $before["active"] === $active) {
                throw new ConcurrentModificationException("The template state changed. Reload it.");
            }
            $this->templates->save(
                $id,
                $actor,
                $version,
                $before["name"],
                $active,
                $before["section_count"],
                $before["encrypted_content"],
                $this->now(),
            );
            return $this->record(
                $id,
                $operation,
                $actor,
                $version + 1,
                $hash,
                $active ? "restored" : "retired",
                $note,
                $before,
            );
        });
    }
    /** @param array<string, mixed>|null $before
     * @return array<string, mixed>
     */
    private function record(
        EntityId $id,
        EntityId $operation,
        EntityId $actor,
        int $version,
        string $hash,
        string $action,
        string $note,
        ?array $before,
    ): array {
        $after = $this->current($id);
        $result = [
            "template_id" => $id->value,
            "operation_id" => $operation->value,
            "version" => $version,
            "name" => $after["name"],
            "active" => $after["active"],
        ];
        $snapshot = fn(?array $row): ?array => $row === null
            ? null
            : ["name" => $row["name"], "active" => $row["active"], "sections" => $this->recipe($row)->describe()];
        $data = [
            "action" => $action,
            "note" => $note,
            "before" => $snapshot($before),
            "after" => $snapshot($after),
            "result" => $result,
        ];
        $this->templates->record(
            $operation,
            $id,
            $actor,
            $version,
            $hash,
            $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            $this->now(),
        );
        $this->audit->record($actor, "catalog.template." . $action, $id);
        return $result;
    }
    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(array $row, EntityId $id, EntityId $actor, ?string $hash): array
    {
        if ($row["actor_id"] !== $actor->value || $row["template_id"] !== $id->value) {
            throw new AccessDeniedException("Access denied.");
        }
        if ($hash !== null && !hash_equals($row["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This reference belongs to another template change.");
        }
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR)["result"];
    }
    /** @return array<string, mixed> */
    public function receipt(EntityId $id, EntityId $operation): array
    {
        $actor = $this->grant();
        $row =
        $this->templates->operation($operation) ?? throw new RecordNotFoundException("Template receipt not found.");
        return $this->replay($row, $id, $actor, null);
    }
    /** @return array<string, mixed> */
    public function history(EntityId $id, int $afterVersion = -1): array
    {
        $this->grant();
        $this->current($id);
        $rows = $this->templates->history($id, $afterVersion, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(function (array $row): array {
            $data = json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR);
            return [
                "id" => $row["id"],
                "version" => (int) $row["version"],
                "created_at" => $row["created_at"],
                "action" => $data["action"],
                "note" => $data["note"],
                "name" => $data["after"]["name"],
                "active" => $data["after"]["active"],
                "section_count" => count($data["after"]["sections"]),
            ];
        }, $rows);
        return ["items" => $items, "next_after_version" => $more ? (int) $rows[49]["version"] : null];
    }
}
