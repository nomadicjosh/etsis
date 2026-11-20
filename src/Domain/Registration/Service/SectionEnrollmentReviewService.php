<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\Repository\SectionEnrollmentReviewRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Advisory planning from bounded current projections; never cancels, charges or allocates seats. */
final readonly class SectionEnrollmentReviewService
{
    public function __construct(
        private SectionEnrollmentReviewRepository $records,
        private StudentAccess $access,
    ) {
    }

    public function page(
        string $state = "below",
        string $search = "",
        bool $includeInactive = false,
        ?EntityId $term = null,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize();
        if (
            !in_array($state, ["below", "met", "unset", "all"], true) ||
            mb_strlen($search) > 80 ||
            !mb_check_encoding($search, "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new InvalidArgumentException("Choose a valid enrollment review scope.");
        }
        $result = $this->records->page($state, trim($search), $includeInactive, $term, $after);
        if ($this->authorize()->value !== $actor->value) {
            throw new AccessDeniedException("The workspace account changed.");
        }
        return $result;
    }

    private function authorize(): EntityId
    {
        try {
            return $this->access->requirePermission("sis:catalog:manage");
        } catch (AccessDeniedException) {
            return $this->access->requirePermission("sis:registration:manage");
        }
    }
}
