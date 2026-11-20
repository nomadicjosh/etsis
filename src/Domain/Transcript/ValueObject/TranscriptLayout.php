<?php

declare(strict_types=1);

namespace Domain\Transcript\ValueObject;

use InvalidArgumentException;

/** Retained presentation choice; it supplies no academic rules or release authority. */
final readonly class TranscriptLayout
{
    public function __construct(
        public string $code,
        public string $label,
        public string $paper,
        public string $revision,
    ) {
        if (
            preg_match('/^[a-z][a-z0-9-]{1,39}$/D', $code) !== 1 ||
            trim($label) === '' || mb_strlen($label) > 80 || !mb_check_encoding($label, 'UTF-8') ||
            preg_match('/[\x00-\x1F\x7F]/', $label) || !in_array($paper, ['letter', 'a4'], true) ||
            preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1
        ) {
            throw new InvalidArgumentException('Use a registered transcript layout and paper size.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 4 || array_diff(array_keys($data), ['code', 'label', 'paper', 'revision']) !== [] ||
            !array_all($data, static fn(mixed $value): bool => is_string($value))
        ) {
            throw new InvalidArgumentException('Provide the complete retained transcript layout.');
        }
        return new self($data['code'], $data['label'], $data['paper'], $data['revision']);
    }

    /** @return array<string,string> */
    public function describe(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'paper' => $this->paper, 'revision' => $this->revision];
    }
}
