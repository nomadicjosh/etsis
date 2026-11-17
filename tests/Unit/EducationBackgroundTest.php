<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Person\ValueObject\EducationBackground;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class EducationBackgroundTest extends TestCase
{
    private function entry(): array
    {
        return ['key' => 'college_one', 'institution' => 'Fictional College', 'gpa' => '7.2500', 'gpa_max' => '10'];
    }

    public function testUnknownDatesAndDifferentSourceGpaScalesArePreservedWithoutConversion(): void
    {
        $value = EducationBackground::fromInput([$this->entry()], 1);
        self::assertSame('', $value->entries[0]['start_date']);
        self::assertSame('7.2500', $value->entries[0]['gpa']);
        self::assertSame('10', $value->entries[0]['gpa_max']);
        self::assertSame($value->facts(), EducationBackground::restore($value->facts())->facts());
        $again = $this->entry();
        $again['key'] = 'college_second_period';
        self::assertCount(2, EducationBackground::fromInput([$this->entry(), $again], 1)->entries);
    }

    public function testInvalidChronologyIncompleteOrOutOfScaleGpaAndReviewForgeryAreRejected(): void
    {
        foreach (
            [
            ['start_date' => '2023-02-29'], ['start_date' => '2024-02-29', 'end_date' => '2024-02-28'],
            ['conferred_date' => '2024-05-01'], ['gpa_max' => ''], ['gpa_max' => '0'], ['gpa_max' => '4'],
            ['gpa' => '1e2'], ['gpa' => '-1'], ['gpa' => '7.12345'], ['review_status' => 'reviewed'],
            ['institution' => "Fictional\0College"], ['institution' => ''],
            ] as $invalid
        ) {
            try {
                EducationBackground::fromInput([array_replace($this->entry(), $invalid)], 1);
                self::fail('Invalid background entry was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReviewTransitionsCannotReplaceDetailsOrKeepAnApprovalAfterDetailsChange(): void
    {
        $first = EducationBackground::fromInput([$this->entry()], 1);
        $reviewed = $first->review('college_one', 'reviewed', 'Fictional review feedback.');
        $reviewed->assertTransition($first, 'reviewed', 'college_one', 'Fictional review feedback.');
        $changed = EducationBackground::fromInput(
            [array_replace($this->entry(), ['institution' => 'Other College'])],
            3,
            $reviewed
        );
        self::assertSame('unreviewed', $changed->entries[0]['review_status']);
        self::assertSame('', $changed->entries[0]['feedback']);
        $this->expectException(CorruptHistoryException::class);
        $changed->assertTransition($reviewed, 'reviewed', 'college_one', 'Fictional review feedback.');
    }

    public function testBackgroundCollectionsAreBoundedAndDoNotExposeValuesInDebugging(): void
    {
        $value = EducationBackground::fromInput([$this->entry()], 1);
        self::assertStringNotContainsString('Fictional College', print_r($value, true));
        $this->expectException(ValidationException::class);
        EducationBackground::fromInput(array_fill(0, 21, $this->entry()), 1);
    }

    public function testMalformedStoredCollectionKeysAreRejectedBeforeTheyCanBecomeAnIncompleteProjection(): void
    {
        $facts = EducationBackground::fromInput([$this->entry()], 1)->facts();
        $facts['entries'] = ['unexpected_key' => $facts['entries'][0]];
        $this->expectException(CorruptHistoryException::class);
        EducationBackground::restore($facts);
    }
}
