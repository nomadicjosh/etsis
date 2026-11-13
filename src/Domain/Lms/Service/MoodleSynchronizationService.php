<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

/** One scheduler entry point, rotating bounded course, account and roster steps. */
final readonly class MoodleSynchronizationService
{
    public function __construct(
        private MoodleSectionSyncPolicy $policy,
        private MoodleCourseProvisioningService $courses,
        private MoodleProvisioningWorker $accounts,
        private MoodleSectionSyncService $sections,
        private MoodleCategoryService $categories,
    ) {
    }
    public function runOne(int $step): string
    {
        if ($step < 0) {
            throw new \InvalidArgumentException("Synchronization step must be nonnegative.");
        }
        if (!$this->policy->enabled()) {
            return "disabled";
        }
        for ($offset = 0; $offset < 4; $offset++) {
            $stage = ($step + $offset) % 4;
            $result = match ($stage) {
                0 => $this->categories->runOne(),
                1 => $this->courses->runOne(),
                2 => $this->accounts->runOne(),
                3 => $this->sections->runOne(),
            };
            if (!in_array($result, ["idle", "disabled"], true)) {
                return $stage === 2 && $result !== "review_required" ? "account_" . $result : $result;
            }
        }
        return "idle";
    }
}
