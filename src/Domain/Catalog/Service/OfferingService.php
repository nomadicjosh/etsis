<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Section;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class OfferingService
{
    public function __construct(
        private CatalogWorkflowRepository $operations,
        private CatalogRevisionRepository $catalog,
        private RegistrationRepository $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private CoursePublicationService $publications,
        private \Domain\Registration\Service\SectionCourseBindingService $bindingService,
        private \Domain\Registration\Repository\SectionCourseBindingRepository $bindings,
    ) {
    }

    /** @return array<string, mixed> */
    public function review(OfferingPlan $plan): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($plan): array {
            $this->lock($plan);
            $context = $this->context($plan);
            return $context + [
                "revision" => $this->revision($context),
                "operation_id" => EntityId::generate()->value,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function save(EntityId $operation, OfferingPlan $plan, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $request = [
            "term_id" => $plan->term->value,
            "sections" => $plan->describe(),
            "revision" => $revision,
        ];
        return $this->transactions->run(function () use (
            $operation,
            $plan,
            $revision,
            $actor,
            $request,
        ): array {
            $this->lock($plan);
            $existing = $this->operations->operation($operation);
            if ($existing !== null) {
                $data = $this->payload($existing);
                if (
                    $existing["actor_id"] !== $actor->value ||
                    $existing["kind"] !== "offerings" ||
                    $data["request"] !== $request
                ) {
                    throw new ConcurrentModificationException(
                        "The operation belongs to another offering plan.",
                    );
                }
                return $this->receipt($existing);
            }
            $context = $this->context($plan);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException("Offering details changed. Review the plan again.");
            }
            foreach ($plan->sections as $s) {
                // Drafts cannot be registered into or advertised through the public catalog.
                $this->registration->addSection(
                    new Section(
                        $s->id,
                        $s->courseId,
                        $s->termId,
                        $s->facultyUserId,
                        $s->code,
                        $s->capacity,
                        false,
                        false,
                        $s->fee,
                        $s->deliveryMode,
                    ),
                );
                $this->audit->record($actor, "section.draft_created", $s->id);
            }
            $this->operations->record(
                $operation,
                $actor,
                "offerings",
                $this->cipher->seal(
                    json_encode(
                        [
                            "request" => $request,
                            "review" => $context,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                $this->now(),
            );
            return $this->saved($operation);
        });
    }

    /** @return array<string, mixed> */
    public function page(?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $rows = $this->operations->offeringPage($after);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = [];
        foreach ($rows as $row) {
            $review = $this->payload($row)["review"];
            $items[] = [
                "operation_id" => $row["id"],
                "created_at" => $row["created_at"],
                "published_at" => $row["published_at"],
                "term_code" => $review["term"]["code"],
                "section_count" => count($review["sections"]),
            ];
        }
        return ["items" => $items, "next_after" => $more ? $rows[49]["id"] : null];
    }

    /** @return array<string, mixed> */
    public function saved(EntityId $id): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        return $this->receipt($this->operation($id));
    }

    /** Current draft setup; selecting a definition here creates no binding.
     * @return array<string, mixed>
     */
    public function preparePublication(EntityId $id): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $routing = $this->payload($this->operation($id))["request"];
        return $this->transactions->run(function () use ($id, $routing): array {
            $this->lockPublication($id, $routing);
            return $this->publicationContext($this->operation($id), [], false);
        });
    }

    /** @param array<string, EntityId> $versions Section identity => explicitly chosen publication identity.
     * @return array<string, mixed>
     */
    public function reviewPublication(
        EntityId $id,
        array $versions,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException("Use a publication reason of at most 1000 characters.");
        }
        // Validate attributed reason before acquiring locks; effective time is captured at the final publication.
        new \Domain\Shared\EventSourcing\EventContext($actor, $this->clock->now(), $id, reason: $reason);
        $routing = $this->payload($this->operation($id))["request"];
        return $this->transactions->run(function () use ($id, $versions, $reason, $actor, $routing): array {
            $this->lockPublication($id, $routing);
            $context = $this->publicationContext($this->operation($id), $versions);
            $selected = array_column($context["sections"], "course_version_id", "id");
            ksort($selected);
            $proof = [
                "kind" => "offering-publication-review.v1",
                "operation_id" => $id->value,
                "actor_id" => $actor->value,
                "revision" => $this->revision($context),
                "versions" => $selected,
                "reason" => $reason,
            ];
            return $context + [
                "reason" => $reason,
                "review_token" => $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function publish(EntityId $id, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 65536) {
                throw new \InvalidArgumentException();
            }
            $proof = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($proof) ||
                ($proof["kind"] ?? null) !== "offering-publication-review.v1" ||
                ($proof["operation_id"] ?? null) !== $id->value
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Review the offering publication again.");
        }
        if ($proof["actor_id"] !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException(
                "This publication review belongs to another staff member.",
            );
        }
        $routing = $this->payload($this->operation($id))["request"];
        $hash = hash("sha256", $token);
        return $this->transactions->run(function () use ($id, $proof, $actor, $routing, $hash): array {
            $this->lockPublication($id, $routing);
            $row = $this->operation($id);
            $data = $this->payload($row);
            if ($row["published_at"] !== null) {
                $accepted = $data["publication"] ?? [];
                if (
                    ($accepted["actor_id"] ?? null) !== $actor->value ||
                    ($accepted["review_hash"] ?? null) !== $hash
                ) {
                    throw new ConcurrentModificationException(
                        "This offering plan was published using another review.",
                    );
                }
                return $this->receipt($row);
            }
            $versions = array_map(static fn(string $v): EntityId => new EntityId($v), $proof["versions"]);
            $context = $this->publicationContext($row, $versions);
            if (!hash_equals($this->revision($context), $proof["revision"])) {
                throw new ConcurrentModificationException(
                    "Drafts or course bindings changed. Review publication again.",
                );
            }
            foreach ($context["sections"] as $section) {
                $sectionId = new EntityId($section["id"]);
                if (!$section["was_bound"]) {
                    $binding = $this->bindingService->review(
                        $sectionId,
                        new EntityId($section["course_version_id"]),
                        $proof["reason"],
                        $id,
                    );
                    $this->bindingService->commit($sectionId, $binding["review_token"]);
                }
                $this->catalog->save("sections", $sectionId, [
                    "active" => 1,
                    "web_registration" => (int) $section["web_registration"],
                ]);
                $this->audit->record($actor, "section.published", $sectionId);
            }
            $data["publication"] = [
                "actor_id" => $actor->value,
                "review_hash" => $hash,
                "reason" => $proof["reason"],
                "review" => $context,
            ];
            $this->operations->published(
                $id,
                $this->now(),
                $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
            );
            $this->audit->record($actor, "catalog.offerings_published", $id);
            return $this->saved($id);
        });
    }

    private function lock(OfferingPlan $plan): void
    {
        $courses = array_unique(array_column($plan->describe(), "course_id"));
        sort($courses, SORT_STRING);
        foreach ($courses as $id) {
            $this->catalog->lock("courses", new EntityId($id));
        }
        $this->catalog->lock("terms", $plan->term);
    }

    /** @return array<string, mixed> */
    private function context(OfferingPlan $plan): array
    {
        $term = $this->catalog->get("terms", $plan->term);
        $this->registration->term($plan->term);
        $rows = [];
        foreach ($plan->describe() as $section) {
            if ($this->operations->codeExists("sections", $section["code"], $plan->term)) {
                throw new ValidationException([
                    [
                        "field" => "code",
                        "message" => "A section code is already in use. Choose a new code before saving.",
                    ],
                ]);
            }
            $course = $this->registration->course(new EntityId($section["course_id"]));
            if (!$course->active) {
                throw new ValidationException([
                    ["field" => "course_id", "message" => "Activate the course before offering it."],
                ]);
            }
            $faculty =
            $section["faculty_user_id"] === null
            ? null
            : $this->operations->faculty(new EntityId($section["faculty_user_id"]));
            $rows[] = $section + [
                "course_code" => $course->code,
                "course_title" => $course->title,
                "credits_milli" => $course->creditsMilli,
                "faculty_name" => $faculty["name"] ?? null,
            ];
        }
        return ["term" => $term, "sections" => $rows];
    }

    /** @param array<string, mixed> $row
     * @param array<string, EntityId> $versions
     * @return array<string, mixed>
     */
    private function publicationContext(array $row, array $versions, bool $requireVersions = true): array
    {
        if ($row["published_at"] !== null) {
            throw new ValidationException([
                ["field" => "operation", "message" => "This offering plan is already published."],
            ]);
        }
        $request = $this->payload($row)["request"];
        $sections = $request["sections"];
        if (
            count($versions) > 25 ||
            array_diff(array_keys($versions), array_column($sections, "id")) !== []
        ) {
            throw new ValidationException([
                [
                    "field" => "course_versions",
                    "message" => "Choose definitions only for sections in this plan.",
                ],
            ]);
        }
        $result = [];
        $definitions = [];
        foreach ($sections as $intended) {
            $s = $this->registration->section(new EntityId($intended["id"]));
            $course = $this->registration->course($s->courseId);
            if ($s->active || !$course->active) {
                throw new ValidationException([
                    [
                        "field" => "sections",
                        "message" =>
                            "Publication requires inactive drafts and active courses. Review catalog edits first.",
                    ],
                ]);
            }
            $bound = $this->bindings->bound($s->id);
            $chosen = $versions[$s->id->value] ?? $bound?->versionId;
            if ($bound !== null && $chosen?->value !== $bound->versionId->value) {
                throw new ValidationException([
                    [
                        "field" => "course_versions",
                        "message" => "A retained section definition cannot be replaced.",
                    ],
                ]);
            }
            $hasRecords = $this->bindings->hasStudentRecords($s->id);
            if ($requireVersions && ($chosen === null || ($bound === null && $hasRecords))) {
                throw new ValidationException([
                    [
                        "field" => "course_versions",
                        "message" =>
                            "Choose a published definition for every empty draft section. " .
                            "Student records cannot be rebound.",
                    ],
                ]);
            }
            $published = $bound;
            if ($chosen !== null && ($requireVersions || $bound === null)) {
                $key = $s->courseId->value . ":" . $chosen->value;
                $published = $definitions[$key] ??= $this->publications->publishedDefinition(
                    $s->courseId,
                    $chosen,
                );
                if ($bound !== null && $bound->describe() !== $published->describe()) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
            }
            $faculty = $s->facultyUserId === null ? null : $this->operations->faculty($s->facultyUserId);
            $result[] = [
                "id" => $s->id->value,
                "course_id" => $s->courseId->value,
                "term_id" => $s->termId->value,
                "code" => $s->code,
                "delivery_mode" => $s->deliveryMode->value,
                "capacity" => $s->capacity,
                "faculty_user_id" => $s->facultyUserId?->value,
                "faculty_name" => $faculty["name"] ?? null,
                "course_code" => $published?->core->code ?? $course->code,
                "course_title" => $published?->core->title ?? $course->title,
                "credits_milli" => $published?->core->creditsMilli ?? $course->creditsMilli,
                "course_version_id" => $published?->versionId->value,
                "course_definition" => $published?->describe(),
                "was_bound" => $bound !== null,
                "has_student_records" => $hasRecords,
                "draft_revision" => $this->revision($this->catalog->get("sections", $s->id)),
                "fee_minor" => $s->fee->minor,
                "currency" => $s->fee->currency,
                "web_registration" => $intended["web_registration"],
            ];
        }
        return [
            "operation_id" => $row["id"],
            "term" => $this->catalog->get("terms", new EntityId($request["term_id"])),
            "sections" => $result,
        ];
    }

    /** @param array<string, mixed> $request */
    private function lockPublication(EntityId $operation, array $request): void
    {
        $this->operations->lockOperation($operation);
        $sections = $request["sections"];
        $ids = array_column($sections, "id");
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->catalog->lock("sections", new EntityId($id));
        }
        $courseIds = array_unique(array_column($sections, "course_id"));
        sort($courseIds, SORT_STRING);
        foreach ($courseIds as $id) {
            $this->catalog->lock("courses", new EntityId($id));
        }
        $this->catalog->lock("terms", new EntityId($request["term_id"]));
    }

    /** @return array<string, mixed> */
    private function operation(EntityId $id): array
    {
        $row = $this->operations->operation($id);
        if ($row === null || $row["kind"] !== "offerings") {
            throw new RecordNotFoundException("Offering plan not found.");
        }
        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function receipt(array $row): array
    {
        $data = $this->payload($row);
        return [
            "operation_id" => $row["id"],
            "created_at" => $row["created_at"],
            "published_at" => $row["published_at"],
            "review" => $data["publication"]["review"] ?? $data["review"],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(#[\SensitiveParameter] array $row): array
    {
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $context */
    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format(DATE_ATOM);
    }
}
