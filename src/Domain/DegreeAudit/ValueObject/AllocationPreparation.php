<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Closure;

/** Request-local prepared evidence; never persist or serialize this object. */
final readonly class AllocationPreparation implements \JsonSerializable
{
    /** @param Closure(?AllocationResult):array<string,mixed> $review */
    public function __construct(
        #[\SensitiveParameter] public ?AllocationProblem $problem,
        #[\SensitiveParameter] private Closure $review,
    ) {
    }
    /** Null requests the ordinary independent search; a supplied result uses captured evidence only.
     * @return array<string,mixed>
     */
    public function review(#[\SensitiveParameter] ?AllocationResult $result = null): array
    {
        if ($result !== null && $this->problem === null) {
            throw new \InvalidArgumentException('This component has no supported allocation problem.');
        }
        return ($this->review)($result);
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['preparation' => '[protected academic inputs]'];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
