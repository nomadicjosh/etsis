<?php

declare(strict_types=1);

namespace Domain\Shared\Backup;

final readonly class BackupPlan implements \JsonSerializable
{
    /** @param list<string> $includes */
    public function __construct(
        public string $scope,
        public string $release,
        public array $includes,
        public string $checkpointDirectory,
        public string $checkpointKey,
        public int $historyRows = 2000,
    ) {
        if (
            preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}$/D', $scope) !== 1 ||
            preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}$/D', $release) !==
                1 ||
            $includes === [] ||
            count($includes) > 32 ||
            $historyRows < 1 ||
            $historyRows > 10000
        ) {
            throw new \InvalidArgumentException(
                "Use explicit bounded backup scope, release and paths.",
            );
        }
    }

    public function __debugInfo(): array
    {
        return ["backup_plan" => "[private operator configuration]"];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
