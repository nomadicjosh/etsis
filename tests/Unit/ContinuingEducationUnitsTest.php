<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Catalog\ValueObject\ContinuingEducationUnits;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Registration\ValueObject\OfferingCeuChoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContinuingEducationUnitsTest extends TestCase
{
    public function testEarlierUnknownExplicitNoneAndZeroRemainDistinctWithoutCreditConversion(): void
    {
        $earlier = new CourseDefinition('CE.101', 'Fictional workshop', 3000, []);
        self::assertArrayNotHasKey('continuing_education', $earlier->describe());
        self::assertSame($earlier->describe(), CourseDefinition::fromArray($earlier->describe())->describe());
        foreach ([null, 0, 1250, 999999] as $milli) {
            $units = new ContinuingEducationUnits($milli);
            self::assertEquals($units, ContinuingEducationUnits::stored(json_encode($units->describe())));
            $course = new CourseDefinition('CE.101', 'Fictional workshop', 3000, [], continuingEducation: $units);
            self::assertSame(3000, CourseDefinition::fromArray($course->describe())->creditsMilli);
            self::assertSame(['ceu_milli' => $milli], $course->describe()['continuing_education']);
        }
        self::assertNull(new OfferingCeuChoice()->resolve(null));
        self::assertNull(new OfferingCeuChoice('none')->resolve(new ContinuingEducationUnits(1250))->milli);
        self::assertSame(0, new OfferingCeuChoice('fixed', 0)->resolve(null)->milli);
    }

    public static function invalidValues(): array
    {
        return [['{}'], ['null'], ['{"ceu_milli":"1250"}'], ['{"ceu_milli":1.25}'],
            ['{"ceu_milli":-1}'], ['{"ceu_milli":1000000}'], ['{"ceu_milli":1,"credits":1}'],
            [str_repeat(' ', 129)]];
    }

    #[DataProvider('invalidValues')]
    public function testMalformedCeuStorageFailsClosed(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContinuingEducationUnits::stored($value);
    }

    public static function invalidChoices(): array
    {
        return [['inherit', 0], ['none', 1250], ['fixed', null], ['automatic', null]];
    }

    #[DataProvider('invalidChoices')]
    public function testInvalidOfferingChoicesCannotGuessOrConvertUnits(string $mode, ?int $milli): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OfferingCeuChoice($mode, $milli);
    }
}
