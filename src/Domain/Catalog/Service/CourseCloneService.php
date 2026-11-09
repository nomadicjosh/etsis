<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DateTimeZone;

final readonly class CourseCloneService
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
    ) {
    }

    /** @return array<string, mixed> */
    public function review(EntityId $source): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $routing = array_column($this->operations->prerequisites($source), "id");
        return $this->transactions->run(function () use ($source, $routing): array {
            $this->lock($source, $routing);
            $context = $this->context($source);
            $this->checkRouting($routing, $context);
            return $context + ["revision" => $this->revision($context), "operation_id" => EntityId::generate()->value];
        });
    }

    /** @param list<EntityId> $prerequisites
     * @return array<string, mixed>
     */
    public function commit(EntityId $source, Course $draft, array $prerequisites, string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $ids = array_map(static fn(EntityId $id): string => $id->value, $prerequisites);
        sort($ids, SORT_STRING);
        if ($draft->active || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw new ValidationException([
                ["field" => "prerequisites", "message" => "Choose distinct draft prerequisites."],
            ]);
        }
        $request = [
            "source_id" => $source->value,
            "code" => $draft->code,
            "title" => $draft->title,
            "credits_milli" => $draft->creditsMilli,
            "prerequisites" => $ids,
            "revision" => $revision,
        ];
        $routing = array_column($this->operations->prerequisites($source), "id");
        return $this->transactions->run(function () use (
            $source,
            $draft,
            $ids,
            $revision,
            $request,
            $actor,
            $routing,
        ): array {
            // Acquire dependency locks in the same order before establishing a consistent snapshot.
            $this->lock($source, array_merge($routing, $ids));
            $existing = $this->operations->operation($draft->id);
            if ($existing !== null) {
                $payload = json_decode(
                    $this->cipher->open($existing["encrypted_content"]),
                    true,
                    16,
                    JSON_THROW_ON_ERROR,
                );
                if (
                    $existing["actor_id"] !== $actor->value ||
                    $existing["kind"] !== "course_clone" ||
                    $payload["request"] !== $request
                ) {
                    throw new ConcurrentModificationException("This operation belongs to another catalog request.");
                }
                return $payload["result"];
            }
            $context = $this->context($source);
            $this->checkRouting($routing, $context);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException("The source course changed. Review the clone again.");
            }
            if (array_diff($ids, array_column($context["prerequisites"], "id")) !== []) {
                throw new ValidationException([
                    ["field" => "prerequisites", "message" => "Choose prerequisites from the reviewed source course."],
                ]);
            }
            if ($this->operations->codeExists("courses", $draft->code)) {
                throw new ValidationException([["field" => "code", "message" => "Choose an unused course code."]]);
            }
            $this->registration->addCourse($draft);
            $this->catalog->save("courses", $draft->id, [
                "transcript_title" => $context["source"]["transcript_title"],
                "description" => $context["source"]["description"],
            ]);
            foreach ($ids as $id) {
                $this->registration->addPrerequisite($draft->id, new EntityId($id));
            }
            $result = [
                "id" => $draft->id->value,
                "source_id" => $source->value,
                "code" => $draft->code,
                "title" => $draft->title,
                "credits_milli" => $draft->creditsMilli,
                "active" => false,
                "prerequisites_copied" => count($ids),
            ];
            $this->operations->record(
                $draft->id,
                $actor,
                "course_clone",
                $this->cipher->seal(
                    json_encode(
                        [
                            "request" => $request,
                            "result" => $result,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record($actor, "course.cloned_as_draft", $draft->id);
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function saved(EntityId $id): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $row = $this->operations->operation($id);
        if ($row === null || $row["kind"] !== "course_clone") {
            throw new RecordNotFoundException("Course clone not found.");
        }
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR)["result"];
    }

    /** @param list<string> $dependencies */
    private function lock(EntityId $source, array $dependencies): void
    {
        $ids = array_unique([$source->value, ...$dependencies]);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->catalog->lock("courses", new EntityId($id));
        }
    }

    /** @param list<string> $routing
     * @param array<string, mixed> $context
     */
    private function checkRouting(array $routing, array $context): void
    {
        if ($routing !== array_column($context["prerequisites"], "id")) {
            throw new ConcurrentModificationException("The prerequisite selection changed. Review the course again.");
        }
    }

    /** @return array<string, mixed> */
    private function context(EntityId $source): array
    {
        $this->catalog->lock("courses", $source);
        $course = $this->registration->course($source);
        $prerequisites = $this->operations->prerequisites($source);
        if (count($prerequisites) > 100) {
            throw new ValidationException([
                [
                    "field" => "prerequisites",
                    "message" => "This course has over 100 prerequisites and needs a focused catalog review.",
                ],
            ]);
        }
        $text = $this->catalog->get("courses", $source);
        return [
            "source" => [
                "id" => $source->value,
                "code" => $course->code,
                "title" => $course->title,
                "credits_milli" => $course->creditsMilli,
                "active" => $course->active,
                "transcript_title" => $text["transcript_title"],
                "description" => $text["description"],
            ],
            "prerequisites" => $prerequisites,
        ];
    }

    /** @param array<string, mixed> $context */
    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }
}
