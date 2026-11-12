<?php

declare(strict_types=1);

namespace Domain\User;

use Domain\Shared\ValueObject\EntityId;
use Domain\User\ValueObject\VerifiedLoginCookie;

final readonly class LoginSession implements \JsonSerializable
{
    public function __construct(
        public EntityId $id,
        public EntityId $user,
        #[\SensitiveParameter] public string $cookieHash,
        #[\SensitiveParameter] public string $accountRevision,
        public int $expiresAt,
        public int $observedAt,
        public ?int $revokedAt = null,
    ) {
        foreach ([$cookieHash, $accountRevision] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException("Invalid sign-in reference.");
            }
        }
        if ($expiresAt <= 0 || $observedAt <= 0 || ($revokedAt !== null && $revokedAt <= 0)) {
            throw new \InvalidArgumentException("Invalid sign-in timestamps.");
        }
    }
    public function matches(VerifiedLoginCookie $cookie): bool
    {
        return hash_equals($this->cookieHash, $cookie->cookieHash) && $this->expiresAt === $cookie->expiresAt;
    }
    public function activeAt(int $now): bool
    {
        return $this->revokedAt === null && $now < $this->expiresAt;
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["session" => "[private sign-in reference]"];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
