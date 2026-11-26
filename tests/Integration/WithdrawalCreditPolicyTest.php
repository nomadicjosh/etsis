<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WithdrawalCreditPolicyTest extends TestCase
{
    private function fixture(): SisFixture
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'W', 0, false, false));
        return $s;
    }

    private function windows(int $rate): DropWindows
    {
        return new DropWindows([['through' => null, 'grade_code' => 'W', 'credit_basis_points' => $rate]]);
    }

    public function testCatalogDutyAloneCannotCreateRemoveOrOverrideAnInheritedFinancialSchedule(): void
    {
        foreach (['create', 'remove', 'section'] as $case) {
            $s = $this->fixture();
            if ($case !== 'create') {
                $s->publishDropPolicy($s->term, -1, $this->windows(5000));
            }
            $section = $case === 'section' ? $s->section()->id : null;
            $s->access->staff = false;
            $s->access->grants = ['sis:catalog:manage'];
            self::assertFalse($s->dropPolicies->show($s->term, $section)['can_manage_credits']);
            $before = count($s->sql->rows('sis_history_events'));
            try {
                $s->dropPolicies->review(
                    $s->term,
                    $case === 'remove' ? 0 : -1,
                    $this->windows($case === 'create' ? 5000 : 0),
                    'Fictional changed policy.',
                    $section,
                    $section === null ? null : '2026-08-15'
                );
                self::fail('Catalog authority alone changed a financial schedule: ' . $case);
            } catch (AccessDeniedException) {
                self::assertCount($before, $s->sql->rows('sis_history_events'));
            }
        }
    }

    public function testCurrentFinanceDutyIsRequiredAtConfirmationAndOnAnAcceptedRetry(): void
    {
        $s = $this->fixture();
        $review = $s->dropPolicies->review($s->term, -1, $this->windows(5000), 'Fictional finance approval.');
        $s->access->staff = false;
        $s->access->grants = ['sis:catalog:manage'];
        try {
            $s->dropPolicies->commit($s->term, $review['review_token']);
            self::fail('Lost finance authority was accepted.');
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows('sis_term_drop_policies'));
        }
        $s->access->grants[] = 'sis:finance:post';
        $receipt = $s->dropPolicies->commit($s->term, $review['review_token']);
        $s->clock->time = $s->clock->time->modify('+1 day');
        self::assertSame($receipt, $s->dropPolicies->commit($s->term, $review['review_token']));
        $s->access->grants = ['sis:catalog:manage'];
        $this->expectException(AccessDeniedException::class);
        $s->dropPolicies->commit($s->term, $review['review_token']);
    }

    public function testChangingTheInheritedScheduleInvalidatesAReviewedSectionOverride(): void
    {
        $s = $this->fixture();
        $s->publishDropPolicy($s->term, -1, $this->windows(5000));
        $section = $s->section()->id;
        $review = $s->dropPolicies->review(
            $s->term,
            -1,
            $this->windows(0),
            'Fictional intensive section exception.',
            $section,
            '2026-08-15'
        );
        $s->publishDropPolicy($s->term, 0, $this->windows(7500));
        try {
            $s->dropPolicies->commit($s->term, $review['review_token'], $section);
            self::fail('A changed inherited financial schedule was replaced.');
        } catch (ConcurrentModificationException) {
            self::assertSame(-1, $s->dropPolicies->show($s->term, $section)['version']);
            self::assertSame(7500, $s->dropPolicies->show($s->term, $section)['windows'][0]['credit_basis_points']);
        }
    }

    public function testAnUnpublishedFinancialWindowCannotAuthorizeAnAccountCredit(): void
    {
        $s = $this->fixture();
        $s->sql->insert('sis_term_drop_policies', ['id' => EntityId::generate()->value,
            'term_id' => $s->term->value, 'version' => 0,
            'windows' => json_encode($this->windows(5000)->windows, JSON_THROW_ON_ERROR)]);
        $before = count($s->sql->rows('sis_history_events'));
        try {
            $s->dropPolicies->outcome($s->term, '2026-08-20');
            self::fail('An unapproved financial window was applied.');
        } catch (ValidationException) {
            self::assertCount($before, $s->sql->rows('sis_history_events'));
        }
    }
}
