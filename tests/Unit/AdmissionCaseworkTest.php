<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Admissions\AdmissionCasework;
use Domain\Admissions\ValueObject\CaseworkChecklist;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AdmissionCaseworkTest extends TestCase
{
    private function item(): array
    {
        return ['key' => 'previous_transcript', 'label' => 'Previous transcript', 'instructions' => 'Arrange delivery.',
            'needed' => true, 'visible' => true, 'status' => 'pending', 'due_on' => '2026-12-01'];
    }
    public function testFollowUpUsesEarliestUnfinishedDateAndDoneCaseworkHasNoQueueDeadline(): void
    {
        $id = EntityId::generate();
        $case = AdmissionCasework::fromInput(
            $id,
            ['items' => [$this->item()], 'follow_up_on' => '2026-12-15'],
            null,
            1
        );
        self::assertSame('2026-12-01', $case->nextDueOn());
        $item = $this->item();
        $item['status'] = 'complete';
        $done = AdmissionCasework::fromInput($id, ['items' => [$item], 'follow_up_on' => '2026-12-15',
            'work_status' => 'done'], null, 2);
        self::assertNull($done->nextDueOn());
        self::assertStringNotContainsString('Arrange delivery', print_r($case, true));
    }
    public function testNeededPendingItemsPreventCompletingCaseworkWithoutChangingApplicationStatus(): void
    {
        $this->expectException(ValidationException::class);
        AdmissionCasework::fromInput(
            EntityId::generate(),
            ['items' => [$this->item()], 'work_status' => 'done'],
            null,
            1
        );
    }
    public function testStrictFlagsDatesCollectionsAndUnknownFieldsRejectUnreviewableInput(): void
    {
        foreach (
            [['needed' => 1], ['visible' => 'true'], ['due_on' => '2026-02-30'], ['key' => '../private'],
            ['status' => 'accepted'], ['credential_verified' => true]] as $invalid
        ) {
            try {
                CaseworkChecklist::items([array_replace($this->item(), $invalid)]);
                self::fail('Invalid checklist input was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        CaseworkChecklist::items(array_fill(0, 31, $this->item()));
    }
}
