<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\AcademicRecord\ValueObject\RegisteredCreditRestoration;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegisteredCreditRestorationTest extends TestCase
{
    public static function invalid(): array
    {
        return [
            ["original_milli", -1],
            ["original_milli", 2147483647],
            ["registered_milli", -1],
            ["registered_milli", 1000000],
            ["registered_milli", "4500"],
            ["registered_milli", 4.5],
            ["registered_milli", false],
            ["registered_milli", null],
            ["correction_count", 0],
            ["correction_count", "1"],
            ["correction_count", null],
        ];
    }
    #[DataProvider("invalid")]
    public function testInvalidRecordedQuantitiesCannotBePresentedAsRestorationEvidence(
        string $key,
        mixed $value,
    ): void {
        $facts = ["original_milli" => 3000, "registered_milli" => 4500, "correction_count" => 1];
        $facts[$key] = $value;
        $this->expectException(CorruptHistoryException::class);
        new RegisteredCreditRestoration($facts);
    }
    public function testZeroAndReturnedQuantitiesKeepTheCorrectionCountWithoutInferringAnAward(): void
    {
        foreach ([0, 3000] as $quantity) {
            $facts = [
                "original_milli" => 3000,
                "registered_milli" => $quantity,
                "correction_count" => 2,
            ];
            self::assertSame($facts, new RegisteredCreditRestoration($facts)->facts);
        }
        $this->expectException(CorruptHistoryException::class);
        new RegisteredCreditRestoration($facts + ["reason" => "fictional private note"]);
    }
}
