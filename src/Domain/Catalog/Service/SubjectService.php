<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\SubjectRepository;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class SubjectService
{
    public function __construct(
        private SubjectRepository $subjects,
        private CatalogRevisionRepository $catalog,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function page(string $search = "", ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($search) > 100) {
            throw new \InvalidArgumentException("Use a shorter subject search.");
        }
        $rows = $this->subjects->page(trim($search), $after);
        return [
            "items" => array_map(
                fn(array $r): array => $r + ["revision" => $this->revision($r)],
                array_slice($rows, 0, 50),
            ),
            "next_after" => count($rows) > 50 ? $rows[49]["id"] : null,
        ];
    }
    /** @return array<string, mixed> */
    public function save(?EntityId $id, string $code, string $title, bool $active, ?string $revision): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $subject = new SubjectSnapshot($id ?? EntityId::generate(), strtoupper(trim($code)), trim($title));
        return $this->transactions->run(function () use ($id, $subject, $active, $revision, $actor): array {
            if ($id !== null) {
                $this->subjects->lock($id);
                if ($revision !== $this->revision($this->subjects->get($id))) {
                    throw new ConcurrentModificationException("Reload the subject before saving.");
                }
            }
            $this->authorize($actor);
            $this->subjects->save($subject, $active, $id === null);
            $this->audit->record($actor, $id === null ? "subject.created" : "subject.updated", $subject->id);
            $row = $this->subjects->get($subject->id);
            return $row + ["revision" => $this->revision($row)];
        });
    }
    /** @return array<string, mixed> */
    public function course(EntityId $id): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $course = $this->catalog->get("courses", $id);
        $subject = $this->subjects->course($id)?->describe();
        return [
            "course" => ["id" => $id->value, "code" => $course["code"], "title" => $course["title"]],
            "subject" => $subject,
            "revision" => $this->revision($subject ?? []),
        ];
    }
    public function assign(EntityId $course, ?EntityId $subject, string $revision): void
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->transactions->run(function () use ($course, $subject, $revision, $actor): void {
            $this->catalog->lock("courses", $course);
            if ($subject !== null) {
                $this->subjects->lock($subject);
            }
            if ($revision !== $this->revision($this->subjects->course($course)?->describe() ?? [])) {
                throw new ConcurrentModificationException("Reload the course subject before saving.");
            }
            $this->authorize($actor);
            if ($subject !== null && !$this->subjects->get($subject)["active"]) {
                throw new ValidationException([
                    ["field" => "subject_id", "message" => "Choose an active subject."],
                ]);
            }
            $this->subjects->assign($course, $subject);
            $this->audit->record($actor, "course.subject_assigned", $course);
        });
    }
    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }
    /** @param array<string, mixed> $row */
    private function revision(array $row): string
    {
        ksort($row);
        return hash(
            "sha256",
            json_encode(
                array_map(
                    static fn(mixed $v): ?string => $v === null
                        ? null
                        : (is_bool($v)
                            ? (string) (int) $v
                            : (string) $v),
                    $row,
                ),
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
