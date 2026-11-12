<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** External installation identity, not an etSIS primary key or a credential. */
final readonly class MoodleInstanceIdentity implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private string $identity)
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $identity) !== 1) {
            throw new \InvalidArgumentException("A valid Moodle installation identity is required.");
        }
    }
    public function fingerprint(): string
    {
        return hash("sha256", $this->identity);
    }
    public function matches(self $other): bool
    {
        return hash_equals($this->identity, $other->identity);
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["instance" => "[private installation identity]"];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
