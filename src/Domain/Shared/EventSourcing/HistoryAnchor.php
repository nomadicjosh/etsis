<?php

declare(strict_types=1);

namespace Domain\Shared\EventSourcing;

use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use JsonSerializable;

/** Explicit operator checkpoint, never an academic fact or an independently stored event journal. */
final readonly class HistoryAnchor implements JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        if (
            count($data) !== 5 || ($data['format'] ?? null) !== 1 ||
            !is_string($data['id'] ?? null) || !is_string($data['scope'] ?? null) ||
            !is_string($data['captured_at'] ?? null) || !is_array($data['inventory'] ?? null) ||
            count($data['inventory']) !== 4 ||
            preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,63}$/D', $data['scope']) !== 1 ||
            !is_string($data['inventory']['digest'] ?? null) ||
            preg_match('/^[a-f0-9]{64}$/D', $data['inventory']['digest']) !== 1
        ) {
            throw new InvalidArgumentException('The history checkpoint could not be validated.');
        }
        new EntityId($data['id']);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['captured_at']);
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $data['captured_at']) {
            throw new InvalidArgumentException('The history checkpoint could not be validated.');
        }
        foreach (['streams', 'events', 'operations'] as $key) {
            if (!is_int($data['inventory'][$key] ?? null) || $data['inventory'][$key] < 0) {
                throw new InvalidArgumentException('The history checkpoint could not be validated.');
            }
        }
        $count = $data['inventory']['streams'] + $data['inventory']['events'] + $data['inventory']['operations'];
        if ($count > 10000) {
            throw new InvalidArgumentException('The history checkpoint exceeds the inventory limit.');
        }
    }

    /** Minimized command output; scope and digest remain in the protected artifact. */
    public function describe(string $status): array
    {
        return [
            'status' => $status,
            'checkpoint_id' => $this->data['id'],
            'captured_at' => $this->data['captured_at'],
            'streams' => $this->data['inventory']['streams'],
            'events' => $this->data['inventory']['events'],
            'operations' => $this->data['inventory']['operations'],
        ];
    }

    public function jsonSerialize(): array
    {
        return ['checkpoint' => '[protected history checkpoint]'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
