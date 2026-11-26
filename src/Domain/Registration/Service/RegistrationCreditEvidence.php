<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\SelectedRegistrationCredits;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Authenticate original choice rules without consulting today's catalog or selected offering. */
final class RegistrationCreditEvidence
{
    /** Verify the selected offering before accepting a new variable-credit waiting request. */
    public static function selected(
        SelectedRegistrationCredits $choice,
        \Domain\Registration\Section $section,
        \Domain\Catalog\Course $course,
        HistoricalEventStore $history,
        HistoryReadBudget $budget,
    ): void {
        $choice->routing($course->publicationVersionId?->value, $section->publicationVersionId?->value);
        $published = new RetainedSectionReader($history)->publications([
            ['section_id' => $section->id->value, 'version_id' => $choice->sectionVersion->value],
        ], $budget)[$section->id->value][$choice->sectionVersion->value];
        $binding = $history->latest(SectionOfferingSelection::stream($section->id));
        if (
            $binding === null || $binding->event->payload()['publication_id'] !== $published->publication->value
            || $binding->event->payload()['definition_hash'] !== $published->fingerprint()
            || !$published->matchesOffering($section, $course)
        ) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'credits_milli', 'message' =>
                \Codefy\Framework\Helpers\trans_html(
                    'Choose academic credits under this section’s published variable-credit policy.'
                )]]);
        }
        $retained = new PublishedCourseReader($history)->version($course->id, $choice->courseVersion, $budget);
        if ($retained->definition->creditPolicy?->describe() !== $choice->policy->describe()) {
            throw new CorruptHistoryException();
        }
    }

    public static function verify(
        #[\SensitiveParameter] SelectedRegistrationCredits $choice,
        EntityId $section,
        EntityId $course,
        SectionVersionRepository $versions,
        HistoricalEventStore $history,
        HistoryReadBudget $budget,
    ): void {
        $retained = $versions->find($choice->sectionVersion);
        if (
            $retained === null || $retained->section->value !== $section->value
            || ($retained->definition->data['student_selectable_credits'] ?? false) !== true
            || $retained->definition->data['course_id'] !== $course->value
            || $retained->definition->data['course_version_id'] !== $choice->courseVersion->value
        ) {
            throw new CorruptHistoryException();
        }
        $protected = new RetainedSectionReader($history)->publications([
            ['section_id' => $section->value, 'version_id' => $choice->sectionVersion->value],
        ], $budget)[$section->value][$choice->sectionVersion->value];
        if ($protected->fingerprint() !== $retained->fingerprint()) {
            throw new CorruptHistoryException();
        }
        $published = new PublishedCourseReader($history)->version($course, $choice->courseVersion, $budget);
        if ($published->definition->creditPolicy?->describe() !== $choice->policy->describe()) {
            throw new CorruptHistoryException();
        }
    }
}
