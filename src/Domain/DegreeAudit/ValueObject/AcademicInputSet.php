<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Internal captured inputs, not proof of absolute historical completeness. Never serialize directly. */
final readonly class AcademicInputSet implements \JsonSerializable
{
    /** @param list<array<string,mixed>> $items Current work only.
     * @param list<array<string,mixed>> $references Restricted references, including verified early removals.
     */
    public function __construct(
        public AcademicInputStatus $status,
        public array $items = [],
        public array $references = [],
    ) {
        if (
            in_array($status, [AcademicInputStatus::Limited, AcademicInputStatus::Inconsistent], true) &&
            ($items !== [] || $references !== [])
        ) {
            throw new \InvalidArgumentException("An incomplete verification cannot return partial inputs.");
        }
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["inputs" => "[protected academic inputs]"];
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
