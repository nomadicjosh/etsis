<?php

declare(strict_types=1);

namespace Domain\User\ValueObject;

final readonly class LoginAccountState implements \JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $tokenHash,
        #[\SensitiveParameter] public string $revision,
    ) {
        foreach ([$tokenHash, $revision] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException("Invalid account state.");
            }
        }
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["account" => "[private credential state]"];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
