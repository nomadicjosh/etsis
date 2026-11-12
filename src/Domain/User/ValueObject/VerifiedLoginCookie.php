<?php

declare(strict_types=1);

namespace Domain\User\ValueObject;

/** Verified by the framework-cookie adapter, never hydrated from request form fields. */
final readonly class VerifiedLoginCookie implements \JsonSerializable
{
    public function __construct(
        #[\SensitiveParameter] public string $cookieHash,
        #[\SensitiveParameter] public string $tokenHash,
        public int $expiresAt,
    ) {
        foreach ([$cookieHash, $tokenHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException("Invalid sign-in proof.");
            }
        }
        if ($expiresAt <= 0) {
            throw new \InvalidArgumentException("Invalid sign-in expiry.");
        }
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["sign_in" => "[private verified cookie]"];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
