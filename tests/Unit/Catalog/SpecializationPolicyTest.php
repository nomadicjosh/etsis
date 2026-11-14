<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Catalog\ValueObject\SpecializationPolicy;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecializationPolicyTest extends TestCase
{
    public function testAChoiceCanBeOptionalRequiredOrDisabledWithoutInferringAnAcademicLevel(): void
    {
        self::assertSame(1, new SpecializationPolicy()->effectiveMaximum);
        self::assertSame(1, new SpecializationPolicy(5, 1)->effectiveMaximum);
        self::assertSame(2, new SpecializationPolicy(5, 1, 1, 2)->effectiveMaximum);
        self::assertSame(3, new SpecializationPolicy(5, 3, 2)->effectiveMaximum);
        self::assertSame(0, new SpecializationPolicy(5, 1, 0, 0)->effectiveMaximum);
    }
    public static function invalidPolicies(): array
    {
        return [
            [0, 1, 0, null],
            [101, 1, 0, null],
            [2, 0, 0, null],
            [2, 3, 0, null],
            [2, 1, 0, 3],
            [2, 1, -1, null],
            [2, 1, 2, null],
            [2, 1, 1, 0],
            [2, 1, 0, -1],
        ];
    }
    #[DataProvider('invalidPolicies')]
    public function testProgramChoicesCannotExceedTheirInstitutionOrContradictRequiredCounts(
        int $ceiling,
        int $default,
        int $minimum,
        ?int $maximum,
    ): void {
        $this->expectException(ValidationException::class);
        new SpecializationPolicy($ceiling, $default, $minimum, $maximum);
    }
    private function snapshot(): array
    {
        return [
            'program_id' => str_pad('1', 26, '0', STR_PAD_LEFT),
            'institution' => ['revision' => 1, 'maximum' => 3, 'default_maximum' => 1],
            'program' => ['revision' => 2, 'minimum' => 1, 'maximum' => 2],
            'effective' => ['minimum' => 1, 'maximum' => 2],
            'offered' => [
                [
                    'id' => str_pad('2', 26, '0', STR_PAD_LEFT),
                    'code' => 'ART',
                    'title' => 'Fictional Art',
                    'description' => null,
                    'revision' => 1,
                ],
            ],
        ];
    }
    public function testSnapshotsAreOrderIndependentButPreserveTypedLimitsAndExactDefinitions(): void
    {
        $data = $this->snapshot();
        $snapshot = new ProgramSpecializationSnapshot($data);
        $reordered = array_reverse($data, true);
        $reordered['effective'] = array_reverse($data['effective'], true);
        self::assertSame($snapshot->data, new ProgramSpecializationSnapshot($reordered)->data);
        $data['effective']['maximum'] = '2';
        $this->expectException(\InvalidArgumentException::class);
        new ProgramSpecializationSnapshot($data);
    }
    public function testARequiredUnavailableChoiceOrDuplicateOfferedDefinitionIsRejected(): void
    {
        $cases = [];
        $data = $this->snapshot();
        $data['offered'] = [];
        $cases[] = $data;
        $data = $this->snapshot();
        $data['offered'][] = $data['offered'][0];
        $cases[] = $data;
        $data = $this->snapshot();
        $data['unknown_policy'] = 'forged';
        $cases[] = $data;
        foreach ($cases as $bad) {
            try {
                new ProgramSpecializationSnapshot($bad);
                self::fail('An inconsistent publication was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
