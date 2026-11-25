<?php

declare(strict_types=1);

namespace Domain\Advising;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use JsonSerializable;
use Throwable;

use function Codefy\Framework\Helpers\trans;

/** Bounded encrypted scheduling facts and original operational receipts, not an academic journal. */
final class AdvisingAppointment implements JsonSerializable
{
    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function create(
        EntityId $id,
        EntityId $student,
        EntityId $pursuit,
        EntityId $owner,
        EntityId $host,
        EntityId $hostUser,
        EntityId $actor,
        AppointmentTime $time,
        #[\SensitiveParameter] string $message,
        string $at,
    ): self {
        $item = new self(['purpose' => 'advising.appointment', 'id' => $id->value,
            'student_id' => $student->value, 'pursuit_id' => $pursuit->value,
            'owner_user_id' => $owner->value, 'host_person_id' => $host->value,
            'host_user_id' => $hostUser->value, 'status' => 'scheduled', 'version' => -1,
            'time' => $time->facts(), 'message' => '', 'created_at' => $at, 'updated_at' => $at,
            'actions' => []]);
        $item->apply($id, $actor, 'schedule', -1, $time, $message, $at);
        return $item;
    }

    public static function message(#[\SensitiveParameter] string $message): string
    {
        if (
            !mb_check_encoding($message, 'UTF-8') || mb_strlen(trim($message)) < 1
            || mb_strlen($message) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)
        ) {
            throw new ValidationException([['field' => 'message', 'message' => trans(
                'Enter appointment details or a reason of 1–1,000 characters. All wording is visible to the student.'
            )]]);
        }
        return trim($message);
    }

    public function intent(
        string $action,
        int $version,
        ?AppointmentTime $time,
        #[\SensitiveParameter] string $message,
    ): string {
        return hash('sha256', json_encode(
            [$this->data['id'], $this->data['student_id'],
            $this->data['pursuit_id'], $this->data['owner_user_id'], $this->data['host_person_id'],
            $this->data['host_user_id'], $action, $version, $time?->facts(), self::message($message)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public function retry(EntityId $operation, EntityId $actor, string $intent): ?array
    {
        foreach ($this->data['actions'] as $entry) {
            if ($entry['operation_id'] !== $operation->value) {
                continue;
            }
            if ($entry['actor_id'] !== $actor->value || !hash_equals($entry['intent'], $intent)) {
                throw new ConcurrentModificationException(trans(
                    'This appointment action was already accepted with different details.'
                ));
            }
            return $entry['result'];
        }
        return null;
    }

    public function apply(
        EntityId $operation,
        EntityId $actor,
        string $action,
        int $version,
        ?AppointmentTime $time,
        #[\SensitiveParameter] string $message,
        string $at,
    ): array {
        $intent = $this->intent($action, $version, $time, $message);
        if (($result = $this->retry($operation, $actor, $intent)) !== null) {
            return $result;
        }
        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $at, new DateTimeZone('UTC'));
        $correction = in_array($action, ['correct_held', 'correct_no_show'], true);
        if (
            $stamp === false || $stamp->format('Y-m-d\TH:i:s\Z') !== $at
            || $at < $this->data['updated_at'] || $version !== $this->data['version']
            || ($correction
                ? !in_array($this->data['status'], ['held', 'no_show'], true)
                    || $this->corrections() >= 5 || $this->data['status'] === substr($action, 8)
                : $this->data['status'] !== 'scheduled')
            || !in_array($action, ['schedule', 'reschedule', 'cancel', 'held', 'no_show',
                'correct_held', 'correct_no_show'], true)
            || ($action === 'schedule') !== ($version === -1)
            || (($action === 'schedule' || $action === 'reschedule') !== ($time !== null))
            || ($action === 'reschedule' && $version >= 10)
            || ((in_array($action, ['held', 'no_show'], true) || $correction) && $at < $this->data['time']['ends_at'])
        ) {
            throw new ConcurrentModificationException(trans(
                'The appointment changed or this action is unavailable. Refresh and review its current state.'
            ));
        }
        $time?->requireFuture($stamp);
        $this->data['version']++;
        $this->data['message'] = self::message($message);
        $this->data['updated_at'] = $at;
        if ($time !== null) {
            $this->data['time'] = $time->facts();
        } else {
            $this->data['status'] = match ($action) {
                'cancel' => 'cancelled', 'held', 'correct_held' => 'held',
                'no_show', 'correct_no_show' => 'no_show',
            };
        }
        $result = ['id' => $this->data['id'], 'version' => $this->data['version'],
            'status' => $this->data['status']];
        $this->data['actions'][] = ['operation_id' => $operation->value, 'actor_id' => $actor->value,
            'action' => $action, 'expected_version' => $version, 'time' => $time?->facts(),
            'message' => $this->data['message'], 'at' => $at, 'intent' => $intent, 'result' => $result];
        return $result;
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function corrections(): int
    {
        return count(array_filter($this->data['actions'], static fn(array $entry): bool =>
            in_array($entry['action'], ['correct_held', 'correct_no_show'], true)));
    }

    public function view(): array
    {
        return array_intersect_key($this->data, array_flip([
            'id', 'version', 'status', 'time', 'message', 'created_at', 'updated_at',
        ])) + ['changes' => array_map(static fn(array $a): array => array_intersect_key(
            $a,
            array_flip(['action', 'time', 'message', 'at'])
        ), $this->data['actions'])];
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            if (
                ($data['purpose'] ?? null) !== 'advising.appointment' || !is_array($data['actions'])
                || !array_is_list($data['actions']) || count($data['actions']) < 1 || count($data['actions']) > 17
            ) {
                throw new CorruptHistoryException();
            }
            $first = $data['actions'][0];
            $time = static fn(array $t): AppointmentTime => new AppointmentTime($t['start'], $t['end'], $t['timezone']);
            $item = self::create(
                new EntityId($data['id']),
                new EntityId($data['student_id']),
                new EntityId($data['pursuit_id']),
                new EntityId($data['owner_user_id']),
                new EntityId($data['host_person_id']),
                new EntityId($data['host_user_id']),
                new EntityId($first['actor_id']),
                $time($first['time']),
                $first['message'],
                $first['at']
            );
            foreach (array_slice($data['actions'], 1) as $entry) {
                $item->apply(
                    new EntityId($entry['operation_id']),
                    new EntityId($entry['actor_id']),
                    $entry['action'],
                    $entry['expected_version'],
                    $entry['time'] === null ? null : $time($entry['time']),
                    $entry['message'],
                    $entry['at']
                );
            }
            if ($item->facts() !== $data) {
                throw new CorruptHistoryException();
            }
            return $item;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['advising_appointment' => '[private working record]'];
    }
}
