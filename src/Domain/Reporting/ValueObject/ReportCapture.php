<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** An ephemeral complete capture, protected before persistence and redacted from generic serialization. */
final readonly class ReportCapture implements \JsonSerializable
{
    public const int MAX_ROWS = 5000;
    public const int MAX_BYTES = 8 * 1024 * 1024;

    /** @param list<string> $columns
     * @param list<array<string,scalar|null>> $rows
     */
    public function __construct(public array $columns, #[\SensitiveParameter] public array $rows)
    {
        if (count($rows) > self::MAX_ROWS || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            self::tooLarge();
        }
    }
    public static function tooLarge(): never
    {
        throw new ValidationException([['field' => 'report', 'message' =>
            'This report exceeds the static capture limit of 5,000 rows or 8 MiB. '
            . 'Narrow its filters; no partial capture was saved.']]);
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['capture' => '[protected report results]'];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
