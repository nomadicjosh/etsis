<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

/** Protected, complete output; never generic log or JSON content. */
final readonly class ReportExportArtifact implements \JsonSerializable
{
    public const int MAX_ROWS = 25000;
    public const int MAX_BYTES = 16 * 1024 * 1024;

    /** @param list<string> $chunks
     * @param list<string> $hashes */
    public function __construct(
        #[\SensitiveParameter] public array $chunks,
        public array $hashes,
        public int $rows,
        public int $bytes,
    ) {
        if (
            $rows < 0 || $rows > self::MAX_ROWS || $bytes < 1 || $bytes > self::MAX_BYTES ||
            !array_is_list($chunks) || !array_is_list($hashes) || count($chunks) !== count($hashes) ||
            count($chunks) < 1 || count($chunks) > 501
        ) {
            throw new \InvalidArgumentException('Use a bounded complete report export.');
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['export' => '[protected report output]'];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
