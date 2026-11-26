<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Registration\ValueObject\SectionClassifications;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\Repository\ReportingHierarchyRepository;
use Domain\Catalog\Service\ReportingHierarchyReader;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportingHierarchySnapshotTest extends TestCase
{
    private function group(string $code = "GROUP"): array
    {
        return ["id" => EntityId::generate()->value, "code" => $code, "title" => "Fictional " . $code];
    }

    public function testEarlierThreeFieldFactsStayUnknownAndNewBlanksAndRootsStayDistinct(): void
    {
        foreach ([null, $this->group()] as $group) {
            $old = ["instructional_method" => null, "campus" => null, "reporting_group" => $group];
            $value = SectionClassifications::fromArray($old);
            self::assertSame($old, $value->describe());
            self::assertNull($value->reportingAncestors);
            self::assertNull($value->reportingRoot());
            $known = $old + ["reporting_group_ancestors" => []];
            $value = SectionClassifications::fromArray($known);
            self::assertSame($known, $value->describe());
            self::assertSame($group, $value->reportingRoot()?->describe());
        }
    }

    public function testOnlyCompleteUniqueBoundedPathsAreAccepted(): void
    {
        $group = $this->group();
        $base = ["instructional_method" => null, "campus" => null, "reporting_group" => $group];
        $parents = array_map(fn(int $index): array => $this->group("P" . $index), range(1, 7));
        $accepted = SectionClassifications::fromArray($base + ["reporting_group_ancestors" => $parents]);
        self::assertSame($parents[6], $accepted->reportingRoot()->describe());
        $invalidPaths = [
            null,
            ["not a group"],
            [$group],
            [$parents[0], $parents[0]],
            [...$parents, $this->group("EIGHT")],
            ["bad_key" => $parents[0]],
        ];
        foreach ($invalidPaths as $invalid) {
            try {
                SectionClassifications::fromArray($base + ["reporting_group_ancestors" => $invalid]);
                self::fail("A malformed path was accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPathChangesDuringLockCollectionFailBeforeReadingAnUnlockedReference(): void
    {
        $leaf = $this->group('LEAF');
        $root = $this->group('ROOT');
        $references = $this->createMock(ClassificationRepository::class);
        $references->expects(self::once())->method('get')->with(new EntityId($leaf['id']))
            ->willReturn($leaf + ['kind' => 'reporting_group']);
        $hierarchy = $this->createStub(ReportingHierarchyRepository::class);
        $hierarchy->method('parent')->willReturn(new EntityId($root['id']));
        $reader = new ReportingHierarchyReader($hierarchy, $references);
        $this->expectException(ConcurrentModificationException::class);
        $reader->path(new EntityId($leaf['id']), [$leaf['id']]);
    }
}
