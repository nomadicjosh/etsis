<?php

declare(strict_types=1);

namespace Application\Http;

use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Qubus\Http\ServerRequest;

final readonly class FormInput
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public static function from(ServerRequest $request): self
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new InvalidArgumentException('A parsed form body is required.');
        }

        return new self($body);
    }

    public function text(string $key, int $maximum = 191): string
    {
        $value = $this->data[$key] ?? null;
        if (
            !is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException('A required field is missing or invalid.');
        }

        return trim($value);
    }

    public function optionalText(string $key, int $maximum = 191): ?string
    {
        return !isset($this->data[$key]) ? null : $this->text($key, $maximum);
    }

    public function bodyText(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (
            !is_string($value) || trim($value) === '' || strlen($value) > 100000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException('A bounded text body is required.');
        }

        return $value;
    }

    public function password(string $key = 'password'): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new InvalidArgumentException('A valid password is required.');
        }

        return $value;
    }

    public function optionalBoolean(string $key): ?bool
    {
        return !isset($this->data[$key]) ? null : $this->boolean($key);
    }

    public function id(string $key): EntityId
    {
        return new EntityId($this->text($key, 26));
    }

    public function optionalId(string $key): ?EntityId
    {
        return !isset($this->data[$key]) || $this->data[$key] === '' ? null : $this->id($key);
    }

    public function integer(string $key, int $minimum = 0, int $maximum = 2_000_000_000): int
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('An integer is required.');
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $minimum || $parsed > $maximum) {
            throw new InvalidArgumentException('An integer is outside the permitted range.');
        }

        return $parsed;
    }

    public function optionalInteger(string $key): ?int
    {
        return !isset($this->data[$key]) || $this->data[$key] === '' ? null : $this->integer($key);
    }

    public function boolean(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return $default;
        }
        return match ($this->data[$key]) {
            true, 'true', '1', 1 => true,
            false, 'false', '0', 0 => false,
            default => throw new InvalidArgumentException('A boolean is required.'),
        };
    }

    public function date(string $key): DateTimeImmutable
    {
        $value = $this->text($key, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('An ISO calendar date is required.');
        }

        return $date;
    }

    public function optionalDate(string $key): ?DateTimeImmutable
    {
        return !isset($this->data[$key]) || $this->data[$key] === '' ? null : $this->date($key);
    }

    public function instant(string $key): DateTimeImmutable
    {
        $value = $this->text($key, 25);
        if (str_ends_with($value, 'Z')) {
            $value = substr($value, 0, -1) . '+00:00';
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        if ($instant === false || $instant->format('Y-m-d\TH:i:sP') !== $value) {
            throw new InvalidArgumentException('An ISO timestamp with an explicit timezone offset is required.');
        }

        return $instant;
    }

    /** @return list<self> */
    public function items(string $key, int $maximum = 500): array
    {
        $items = $this->data[$key] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > $maximum) {
            throw new InvalidArgumentException('A bounded list is required.');
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each list item must contain fields.');
            }
            $result[] = new self($item);
        }

        return $result;
    }
}
