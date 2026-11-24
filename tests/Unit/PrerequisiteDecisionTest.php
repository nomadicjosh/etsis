<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrerequisiteDecisionTest extends TestCase
{
    private static function id(int $value): string
    {
        return str_pad((string) $value, 26, '0', STR_PAD_LEFT);
    }

    private static function valid(): array
    {
        return ['assignment_id' => self::id(1), 'required_course_ids' => [self::id(2)],
            'support' => [['course_id' => self::id(2), 'credit_id' => self::id(3), 'origin' => 'institutional',
                'source_id' => self::id(4), 'academic_version' => 2, 'registration_version' => 1, 'pursuit_version' => 1]]];
    }

    public function testSerializationIsRedactedAndCanonicalEvidenceIsIndependentOfObjectKeyOrder(): void
    {
        $original = self::valid();
        $decision = PrerequisiteDecision::fromRetained($original);
        $reordered = $original;
        $reordered['support'][0] = array_reverse($original['support'][0], true);
        self::assertSame($decision->revision(), PrerequisiteDecision::fromRetained($reordered)->revision());
        self::assertSame($original, $decision->retained());
        self::assertSame(0, $decision->missingCount());
        self::assertStringNotContainsString(self::id(3), json_encode($decision, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::id(3), print_r($decision, true));
    }

    public function testIncompleteReviewCannotBeRetainedAsAcceptedEvidence(): void
    {
        $decision = new PrerequisiteDecision(new EntityId(self::id(1)), [self::id(2)], []);
        self::assertFalse($decision->isMet());
        self::assertSame(1, $decision->missingCount());
        $this->expectException(CorruptHistoryException::class);
        $decision->retained();
    }

    public static function malformed(): iterable
    {
        $base = self::valid();
        yield 'unknown fields' => [$base + ['reason' => 'private']];
        yield 'missing assignment' => [array_diff_key($base, ['assignment_id' => true])];
        yield 'invalid identity' => [array_replace($base, ['assignment_id' => '123'])];
        yield 'duplicate requirements' => [array_replace($base, ['required_course_ids' => [self::id(2), self::id(2)]])];
        yield 'requirements map' => [array_replace($base, ['required_course_ids' => ['course' => self::id(2)]])];
        yield 'unknown required identity' => [array_replace($base, ['required_course_ids' => [[]]])];
        yield 'missing passing evidence' => [array_replace($base, ['support' => []])];
        yield 'duplicate support' => [array_replace($base, ['support' => [$base['support'][0], $base['support'][0]]])];
        yield 'support is scalar' => [array_replace($base, ['support' => [true]])];
        foreach (
            ['academic_version' => 0, 'pursuit_version' => 0, 'registration_version' => null,
            'origin' => 'manual', 'course_id' => self::id(5), 'source_id' => '123'] as $field => $value
        ) {
            $bad = $base;
            $bad['support'][0][$field] = $value;
            yield $field => [$bad];
        }
        $bad = $base;
        $bad['support'][0]['origin'] = 'transfer';
        yield 'transfer must not invent an enrollment version' => [$bad];
    }

    #[DataProvider('malformed')]
    public function testMalformedRetainedEvidenceIsRejected(array $facts): void
    {
        $this->expectException(CorruptHistoryException::class);
        PrerequisiteDecision::fromRetained($facts);
    }

    public function testMalformedRequirementOriginsAreRejectedAndEarlierEvidenceKeepsItsShape(): void
    {
        $id = self::id(2);
        $cases = [[], ['institutional', null], ['unknown'], [false], [[]], ['requirement' => 'either']];
        foreach ($cases as $origins) {
            try {
                new \Domain\Catalog\ValueObject\PrerequisiteGroups([[$id]], requirementCreditOrigins: $origins);
                self::fail('Malformed requirement sources were accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $facts = self::valid();
        self::assertSame($facts, PrerequisiteDecision::fromRetained($facts)->retained());
        foreach ([null, [], 'transfer', ['transfer'], [false]] as $origins) {
            $bad = $facts + ['requirement_groups' => [[$id]], 'requirement_credit_origins' => $origins];
            try {
                PrerequisiteDecision::fromRetained($bad);
                self::fail('Malformed or conflicting retained sources were accepted.');
            } catch (CorruptHistoryException) {
                self::assertSame('institutional', $facts['support'][0]['origin']);
            }
        }
    }
}
