<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Domain\Gradebook\ValueObject\GradeComparisonPolicyReference;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GradeComparisonPolicyReferenceTest extends TestCase
{
    public static function invalid(): array
    {
        return [
            [["scope" => "faculty", "publication_id" => null, "version" => null]],
            [["scope" => "section", "publication_id" => null, "version" => 1]],
            [["scope" => "institution", "publication_id" => null, "version" => 1]],
            [["scope" => "institution", "publication_id" => null, "version" => -1]],
            [["scope" => "institution", "publication_id" => null, "version" => "0"]],
            [["scope" => "institution", "publication_id" => null, "version" => 2001]],
            [["scope" => "institution", "publication_id" => str_repeat("0", 26), "version" => 0]],
            [
                [
                    "scope" => "institution",
                    "publication_id" => null,
                    "version" => 0,
                    "reason" => "private",
                ],
            ],
        ];
    }
    #[DataProvider("invalid")]
    public function testInvalidOrTransplantedReferencesAreRejected(array $facts): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GradeComparisonPolicyReference($facts);
    }
    public function testOwnerDescriptionOmitsInternalPublicationIdentity(): void
    {
        $reference = new GradeComparisonPolicyReference([
            "scope" => "institution",
            "publication_id" => str_repeat("0", 26),
            "version" => 1,
        ]);
        self::assertSame(["scope" => "institution", "version" => 1], $reference->describe());
        self::assertSame(
            ["scope" => "section", "version" => null],
            new GradeComparisonPolicyReference([
                "scope" => "section",
                "publication_id" => null,
                "version" => null,
            ])->describe(),
        );
    }
}
