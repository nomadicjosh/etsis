<?php

declare(strict_types=1);

namespace Application\Workflow;

use Domain\Registration\Service\SectionPublicationService;
use Domain\Lms\Service\MoodleCourseProvisioningService;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class SectionPublicationWorkflow
{
    public function __construct(
        private SectionPublicationService $sections,
        private MoodleCourseProvisioningService $moodle,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private StudentAccess $access,
    ) {
    }
    /** @return array<string,mixed> */
    public function setup(EntityId $section): array
    {
        return $this->sections->setup($section) + ["moodle" => $this->moodle->options($section)];
    }
    /** @return array<string,mixed> */
    public function review(EntityId $section, string $start, string $end, string $reason, bool $create): array
    {
        $review = $this->sections->review($section, $start, $end, $reason);
        $proof = $this->moodle->review($section, $review, $create);
        $review["review_token"] = $this->cipher->seal(
            json_encode(
                [
                    "kind" => "section-publication-workflow",
                    "actor" => $review["actor_id"],
                    "section" => $section->value,
                    "publication" => $review["operation_id"],
                    "academic" => $review["review_token"],
                    "moodle" => $proof,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        return $review + ["create_in_moodle" => $create, "moodle_category" => $proof["routing"] ?? null];
    }
    /** @return array<string,mixed> */
    public function publish(EntityId $section, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            $r = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($r) ||
                ($r["kind"] ?? null) !== "section-publication-workflow" ||
                ($r["actor"] ?? null) !== $actor->value ||
                ($r["section"] ?? null) !== $section->value ||
                !is_string($r["academic"] ?? null) ||
                !is_string($r["publication"] ?? null) ||
                !array_key_exists("moodle", $r) ||
                ($r["moodle"] !== null && !is_array($r["moodle"]))
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Review this section again before publishing.");
        }
        return $this->transactions->run(function () use ($section, $r): array {
            if ($r["moodle"] !== null) {
                $this->moodle->prepare($section, new EntityId($r["publication"]), $r["moodle"]);
            }
            $result = $this->sections->publish($section, $r["academic"]);
            if ($result["operation_id"] !== $r["publication"]) {
                throw new \InvalidArgumentException("Publication review changed.");
            }
            if ($r["moodle"] !== null) {
                $this->moodle->enqueue($section, new EntityId($r["publication"]), $r["moodle"]);
            }
            return $result + ["moodle_creation_queued" => $r["moodle"] !== null];
        });
    }
}
