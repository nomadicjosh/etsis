<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Catalog\ValueObject\ComponentExclusions;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class ComponentExclusionsTest extends TestCase
{
    public function testAnExhaustedFeasibilitySearchCannotApproveAnImpossiblePublication(): void
    {
        $offered = $pairs = [];
        for ($index = 0; $index < 32; $index++) {
            $offered[] = ['id' => EntityId::generate()->value, 'kind' => 'minor'];
            if ($index % 2 === 1) {
                $pairs[] = [$offered[$index - 1]['id'], $offered[$index]['id']];
            }
        }
        try {
            new ComponentExclusions($pairs)->publication($offered, [], 17, 17);
            self::fail('An exhausted feasibility search approved a publication.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('bounded publication verification', $error->errors[0]['message']);
        }
    }

    public function testPairsAreSymmetricCanonicalAndStrictlyBounded(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        self::assertSame(new ComponentExclusions([[$a, $b]])->pairs, new ComponentExclusions([[$b, $a]])->pairs);
        foreach (
            [[[$a, $a]], [[$a]], [[$a, $b, $a]], [[$a, $b], [$b, $a]], [['bad', $b]],
            array_fill(0, 33, [$a, $b]), ['pair' => [$a, $b]]] as $invalid
        ) {
            try {
                new ComponentExclusions($invalid);
                self::fail('An invalid incompatible pair was accepted.');
            } catch (ValidationException | \InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testBoundedPublicationSearchAgreesWithExhaustiveSmallSelections(): void
    {
        $offered = [];
        foreach (['major', 'major', 'minor', 'minor', 'track'] as $index => $kind) {
            $offered[] = ['id' => EntityId::generate()->value, 'code' => 'C' . $index, 'title' => 'Fictional ' . $index,
                'kind' => $kind, 'parent_major' => null];
        }
        $offered[4]['parent_major'] = array_intersect_key($offered[0], array_flip(['id', 'code', 'title']));
        $edges = [];
        for ($a = 0; $a < 5; $a++) {
            for ($b = $a + 1; $b < 5; $b++) {
                $edges[] = [$offered[$a]['id'], $offered[$b]['id']];
            }
        }
        foreach (range(1, 64) as $graph) {
            $pairs = array_values(array_filter(
                $edges,
                static fn (int $edge): bool => ($graph & (1 << $edge)) !== 0,
                ARRAY_FILTER_USE_KEY
            ));
            foreach ([[0, 2, 0, 0], [1, 1, 1, 0], [2, 2, 0, 1], [3, 3, 1, 1], [2, 4, 2, 0], [3, 4, 1, 1]] as $case) {
                [$minimum, $maximum, $majors, $tracks] = $case;
                $rules = ['major' => ['minimum' => $majors, 'maximum' => 2],
                    'track' => ['minimum' => $tracks, 'maximum' => 1]];
                $expected = false;
                for ($mask = 0; $mask < 32; $mask++) {
                    $ids = [];
                    foreach ($offered as $index => $choice) {
                        if (($mask & (1 << $index)) !== 0) {
                            $ids[] = $choice['id'];
                        }
                    }
                    if (count($ids) < $minimum || count($ids) > $maximum || (($mask & 16) && !($mask & 1))) {
                        continue;
                    }
                    $counts = ['major' => (int) (bool) ($mask & 1) + (int) (bool) ($mask & 2),
                        'track' => (int) (bool) ($mask & 16)];
                    if ($counts['major'] < $majors || $counts['track'] < $tracks) {
                        continue;
                    }
                    $conflict = false;
                    foreach ($pairs as $pair) {
                        $conflict = $conflict || count(array_intersect($pair, $ids)) === 2;
                    }
                    $expected = $expected || !$conflict;
                }
                try {
                    new ComponentExclusions($pairs)->publication($offered, $rules, $minimum, $maximum);
                    $actual = true;
                } catch (ValidationException) {
                    $actual = false;
                }
                self::assertSame($expected, $actual, 'Graph ' . $graph . ' / ' . json_encode($case));
            }
        }
    }
}
