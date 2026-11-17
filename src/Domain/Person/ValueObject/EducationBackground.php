<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use DateTimeImmutable;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Self-reported prior education. Review never awards academic credit or verifies a credential. */
final readonly class EducationBackground
{
    public const int LIMIT = 20;
    public const array FIELDS = ['key', 'institution', 'institution_code', 'start_date', 'end_date',
        'major', 'degree', 'conferred_date', 'gpa', 'gpa_max'];

    /** @param list<array<string,string>> $entries */
    private function __construct(public array $entries, public int $version)
    {
    }

    /** @param array<mixed> $input */
    public static function fromInput(
        #[\SensitiveParameter] array $input,
        int $version,
        ?self $before = null
    ): self {
        if (!array_is_list($input) || count($input) > self::LIMIT || $version < 1) {
            self::invalid('entries', 'Use up to twenty institutions attended.');
        }
        $previous = array_column($before->entries ?? [], null, 'key');
        $entries = [];
        $seen = [];
        foreach ($input as $index => $raw) {
            if (!is_array($raw) || array_diff(array_keys($raw), self::FIELDS) !== []) {
                self::invalid('entries', 'Review the institution fields.');
            }
            $entry = [];
            foreach (self::FIELDS as $field) {
                $value = $raw[$field] ?? '';
                $limit = match ($field) {
                    'key' => 40, 'institution_code' => 40, default => 191
                };
                if (
                    !is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
                    || preg_match('/[\x00-\x1f\x7f]/', $value)
                ) {
                    self::invalid("entries.$index.$field", 'Enter a valid value for this institution.');
                }
                $entry[$field] = trim($value);
            }
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $entry['key']) !== 1 || isset($seen[$entry['key']])) {
                self::invalid('entries', 'Each institution entry needs its own reference. Reload the editor.');
            }
            $seen[$entry['key']] = true;
            if ($entry['institution'] === '') {
                self::invalid("entries.$index.institution", 'Enter the institution name.');
            }
            foreach (['start_date', 'end_date', 'conferred_date'] as $field) {
                if ($entry[$field] !== '' && !self::date($entry[$field])) {
                    self::invalid("entries.$index.$field", 'Use a valid calendar date.');
                }
            }
            if ($entry['start_date'] !== '') {
                foreach (['end_date', 'conferred_date'] as $field) {
                    if ($entry[$field] !== '' && $entry[$field] < $entry['start_date']) {
                        self::invalid("entries.$index.$field", 'This date cannot precede attendance.');
                    }
                }
            }
            if ($entry['conferred_date'] !== '' && $entry['degree'] === '') {
                self::invalid("entries.$index.degree", 'Name the qualification awarded on this date.');
            }
            if (($entry['gpa'] === '') !== ($entry['gpa_max'] === '')) {
                self::invalid("entries.$index.gpa_max", 'Provide both the reported GPA and its original maximum.');
            }
            if ($entry['gpa'] !== '') {
                $points = self::points($entry['gpa']);
                $maximum = self::points($entry['gpa_max']);
                if ($points === null || $maximum === null || $maximum === 0 || $points > $maximum) {
                    self::invalid("entries.$index.gpa", 'Use a GPA within its original scale (up to four decimals).');
                }
            }
            $old = $previous[$entry['key']] ?? null;
            $same = $old !== null && array_intersect_key($old, array_flip(self::FIELDS)) === $entry;
            $entry['review_status'] = $same ? $old['review_status'] : 'unreviewed';
            $entry['feedback'] = $same ? $old['feedback'] : '';
            $entries[] = $entry;
        }
        return new self($entries, $version);
    }

    public static function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            self::invalid('reason', 'Explain the update in up to 1,000 characters.');
        }
        return $reason;
    }

    public function review(string $key, string $status, #[\SensitiveParameter] string $feedback): self
    {
        if (!in_array($status, ['reviewed', 'changes_requested'], true)) {
            self::invalid('review_status', 'Choose reviewed or request changes.');
        }
        $feedback = self::reason($feedback);
        $entries = $this->entries;
        $found = false;
        foreach ($entries as &$entry) {
            if ($entry['key'] === $key) {
                $entry['review_status'] = $status;
                $entry['feedback'] = $feedback;
                $found = true;
            }
        }
        unset($entry);
        if (!$found) {
            self::invalid('entry_key', 'Reload the institution entry before reviewing it.');
        }
        return new self($entries, $this->version + 1);
    }

    /** @return array{entries:list<array<string,string>>,version:int} */
    public function facts(): array
    {
        return ['entries' => $this->entries, 'version' => $this->version];
    }

    /** @param array<string,mixed> $facts */
    public static function restore(#[\SensitiveParameter] array $facts): self
    {
        try {
            if (
                count($facts) !== 2 || !is_array($facts['entries'] ?? null) || !array_is_list($facts['entries'])
                || !is_int($facts['version'] ?? null)
            ) {
                throw new CorruptHistoryException();
            }
            $input = [];
            foreach ($facts['entries'] as $entry) {
                if (!is_array($entry) || count($entry) !== count(self::FIELDS) + 2) {
                    throw new CorruptHistoryException();
                }
                $input[] = array_intersect_key($entry, array_flip(self::FIELDS));
            }
            $parsed = self::fromInput($input, $facts['version']);
            $entries = $parsed->entries;
            foreach ($entries as $index => &$entry) {
                $raw = $facts['entries'][$index];
                foreach (self::FIELDS as $field) {
                    if (($raw[$field] ?? null) !== $entry[$field]) {
                        throw new CorruptHistoryException();
                    }
                }
                $status = $raw['review_status'] ?? null;
                $feedback = $raw['feedback'] ?? null;
                if (
                    !in_array($status, ['unreviewed', 'reviewed', 'changes_requested'], true) || !is_string($feedback)
                ) {
                    throw new CorruptHistoryException();
                }
                if (
                    ($status === 'unreviewed' && $feedback !== '')
                    || ($status !== 'unreviewed' && self::reason($feedback) !== $feedback)
                ) {
                    throw new CorruptHistoryException();
                }
                $entry['review_status'] = $status;
                $entry['feedback'] = $feedback;
            }
            unset($entry);
            return new self($entries, $parsed->version);
        } catch (ValidationException) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string,mixed> $payload */
    public static function validateEvent(#[\SensitiveParameter] array $payload): void
    {
        $keys = ['operation_id', 'previous_operation_id', 'facts', 'intent', 'action', 'entry_key', 'reason'];
        if (
            count($payload) !== count($keys) || array_diff(array_keys($payload), $keys) !== []
            || !is_array($payload['facts']) || !is_string($payload['intent'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['intent']) !== 1
            || !is_string($payload['operation_id']) || !is_string($payload['previous_operation_id'])
            || !in_array($payload['action'], ['details_updated', 'reviewed', 'changes_requested'], true)
            || !is_string($payload['entry_key']) || !is_string($payload['reason'])
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($payload['operation_id']);
        new EntityId($payload['previous_operation_id']);
        self::restore($payload['facts']);
        try {
            if (self::reason($payload['reason']) !== $payload['reason']) {
                throw new CorruptHistoryException();
            }
        } catch (ValidationException) {
            throw new CorruptHistoryException();
        }
    }

    public function assertTransition(
        ?self $before,
        string $action,
        string $entryKey,
        #[\SensitiveParameter] string $reason
    ): void {
        try {
            if ($action === 'details_updated' && $entryKey === '') {
                $input = array_map(static fn (array $row): array =>
                    array_intersect_key($row, array_flip(self::FIELDS)), $this->entries);
                $expected = self::fromInput($input, ($before?->version ?? 0) + 1, $before);
            } elseif ($before !== null && $action !== 'details_updated') {
                $expected = $before->review($entryKey, $action, $reason);
            } else {
                throw new CorruptHistoryException();
            }
            if ($expected->facts() !== $this->facts() || $this->entries === ($before?->entries ?? [])) {
                throw new CorruptHistoryException();
            }
        } catch (ValidationException) {
            throw new CorruptHistoryException();
        }
    }

    private static function date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1
        && !str_starts_with($value, '0000') && $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function points(string $value): ?int
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?$/D', $value) !== 1) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([['field' => $field, 'message' => $message]]);
    }

    public function __debugInfo(): array
    {
        return ['entries' => '[protected educational background]'];
    }
}
