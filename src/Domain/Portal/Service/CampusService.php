<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Gradebook\Service\GradebookService;
use Domain\Portal\Repository\CampusRepository;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferService;
use DomainException;
use InvalidArgumentException;

final readonly class CampusService
{
    public function __construct(
        private CampusRepository $repository,
        private StudentAccess $access,
        private RegistrationService $registration,
        private TransferService $transfers,
        private GradebookService $grades,
        private StudentAccountService $accounts,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock
    ) {
    }

    /** @return array<string, mixed> */
    public function catalog(?EntityId $term, string $search = '', ?EntityId $after = null): array
    {
        if (mb_strlen($search) > 80 || preg_match('/[\x00-\x1f\x7f]/', $search)) {
            throw new InvalidArgumentException('Invalid catalog search.');
        }
        return $this->repository->sections($term, trim($search), $after);
    }

    /** @return array<string, mixed> */
    public function publicData(): array
    {
        return ['terms' => $this->repository->terms(), 'programs' => $this->repository->programs(),
            'news' => $this->repository->news(false)];
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function programs(string $search = '', ?EntityId $after = null): array
    {
        if (mb_strlen($search) > 80 || preg_match('/[\x00-\x1f\x7f]/', $search)) {
            throw new InvalidArgumentException('Invalid program search.');
        }
        $items = $this->repository->programs(trim($search), $after);
        return ['items' => $items, 'next_after' => count($items) === 100 ? $items[99]['id'] : null];
    }

    /** @return list<array<string, mixed>> */
    public function news(bool $campus = false, ?EntityId $after = null): array
    {
        if ($campus) {
            $this->access->authenticatedUserId();
        }
        return $this->repository->news($campus, $after);
    }

    /** @return array<string, mixed> */
    public function article(string $code, bool $campus = false): array
    {
        if ($campus) {
            $this->access->authenticatedUserId();
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Invalid article.');
        }
        return $this->repository->article($code, $campus);
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        $user = $this->access->authenticatedUserId();
        $data = $this->repository->overview($user);
        $person = new EntityId($data['person']['id']);
        $contact = $this->repository->contact($person);
        $data['contact'] = $contact === null ? ['preferred_name' => '', 'phone' => '', 'address' => '', 'version' => 0]
        : json_decode($this->cipher->open($contact['encrypted_contact']), true, flags: JSON_THROW_ON_ERROR)
        + ['version' => (int) $contact['version']];
        $data['news'] = $this->repository->news(true);
        $data['transcript_requests'] = $this->repository->transcriptRequests($user);
        $data['request_id'] = EntityId::generate()->value;
        $data['payment_id'] = EntityId::generate()->value;
        foreach ($data['students'] as &$student) {
            $student['enrollments'] = $this->repository->enrollments(new EntityId($student['id']));
        }
        unset($student);
        foreach ($data['accounts'] as &$account) {
            $statement = $this->accounts->account(new AccountId($account['id']));
            $account['balance_minor'] = $statement->balance()->minor;
        }
        unset($account);
        return $data;
    }

    public function saveContact(string $preferredName, string $phone, string $address, int $version): void
    {
        $user = $this->access->authenticatedUserId();
        $errors = [];
        $fields = ['preferred_name' => [$preferredName, 80], 'phone' => [$phone, 40], 'address' => [$address, 500]];
        foreach ($fields as $field => [$value, $limit]) {
            if (mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                $errors[] = ['field' => $field, 'message' => 'Enter a shorter valid value.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->transactions->run(function () use ($user, $preferredName, $phone, $address, $version): void {
            $person = $this->repository->personForUser($user);
            $this->repository->saveContact($person, $this->cipher->seal(json_encode([
                'preferred_name' => trim($preferredName), 'phone' => trim($phone), 'address' => trim($address),
            ], JSON_THROW_ON_ERROR)), $version);
            $this->audit->record($user, 'portal.contact_updated', $person);
        });
    }

    public function register(EntityId $student, EntityId $section): void
    {
        $this->repository->ownedStudent($this->access->authenticatedUserId(), $student);
        $this->registration->register($student, $section, false);
    }

    public function drop(EntityId $student, EntityId $section): void
    {
        $this->repository->ownedStudent($this->access->authenticatedUserId(), $student);
        $this->registration->drop($student, $section);
    }

    /** @return list<array<string, mixed>> */
    public function transcript(EntityId $student): array
    {
        $this->repository->ownedStudent($this->access->authenticatedUserId(), $student);
        return $this->transfers->transcript($student);
    }

    /** @return list<array<string, mixed>> */
    public function scores(EntityId $student, EntityId $section): array
    {
        $this->repository->ownedStudent($this->access->authenticatedUserId(), $student);
        return $this->grades->scores($student, $section);
    }

    /** @return array<string, mixed> */
    public function statement(AccountId $id): array
    {
        $user = $this->access->authenticatedUserId();
        $this->repository->requireOwnedAccount($user, new EntityId((string) $id));
        $account = $this->accounts->account($id);
        $this->repository->ownedStudent($user, $account->studentId());
        return ['id' => (string) $id, 'currency' => $account->balance()->currency,
            'balance_minor' => $account->balance()->minor, 'entries' => $account->entries()];
    }

    public function requestTranscript(EntityId $request, EntityId $student, string $delivery, string $recipient): void
    {
        $user = $this->access->authenticatedUserId();
        $this->repository->ownedStudent($user, $student);
        if (
            !in_array($delivery, ['pickup', 'electronic'], true)
            || ($delivery === 'electronic' && (strlen($recipient) > 191
                || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false))
        ) {
            throw new ValidationException([['field' => 'recipient',
                'message' => 'Choose pickup or enter a valid recipient email.']]);
        }
        $this->transactions->run(function () use ($request, $student, $user, $delivery, $recipient): void {
            // Serialize retries and never turn a repeated click into duplicate requests.
            $person = $this->repository->personForUser($user);
            $this->repository->lockPerson($person);
            try {
                $existing = $this->repository->transcriptRequest($request);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            if ($existing !== null) {
                if (
                    $existing['owner_user_id'] !== $user->value || $existing['student_id'] !== $student->value
                    || $existing['delivery'] !== $delivery || $this->cipher->open($existing['encrypted_recipient'])
                        !== ($delivery === 'electronic' ? $recipient : '')
                ) {
                    throw new DomainException('This request identifier has already been used.');
                }
                return;
            }
            $this->repository->addTranscriptRequest($request, ['student_id' => $student->value,
                'owner_user_id' => $user->value, 'delivery' => $delivery,
                'encrypted_recipient' => $this->cipher->seal($delivery === 'electronic' ? $recipient : ''),
                'status' => 'pending', 'requested_at' => $this->clock->now()->format('Y-m-d\TH:i:sP'), 'version' => 0]);
            $this->audit->record($user, 'transcript.requested', $request);
        });
    }

    /** @return list<array<string, mixed>> */
    public function transcriptQueue(?EntityId $after = null): array
    {
        $this->access->requirePermission('sis:transcript:manage');
        return $this->repository->transcriptRequests(null, $after);
    }

    /** @return array<string, mixed> */
    public function transcriptRequest(EntityId $id): array
    {
        $user = $this->access->authenticatedUserId();
        $record = $this->repository->transcriptRequest($id);
        if ($record['owner_user_id'] !== $user->value) {
            $this->access->requirePermission('sis:transcript:manage');
        }
        $record['recipient'] = $this->cipher->open($record['encrypted_recipient']);
        unset($record['encrypted_recipient']);
        return $record;
    }

    public function transitionTranscript(EntityId $id, string $status, int $version): void
    {
        $this->transactions->run(function () use ($id, $status, $version): void {
            $actor = $this->access->authenticatedUserId();
            $record = $this->repository->transcriptRequest($id);
            if ($status === 'cancelled') {
                if ($record['owner_user_id'] !== $actor->value) {
                    throw new AccessDeniedException('Access denied.');
                }
                if ($record['status'] !== 'pending') {
                    throw new DomainException('Only pending requests can be cancelled.');
                }
            } else {
                $this->access->requirePermission('sis:transcript:manage');
                if (
                    !(($record['status'] === 'pending' && $status === 'ready')
                    || ($record['status'] === 'ready' && $status === 'fulfilled'))
                ) {
                    throw new DomainException('This transcript transition is not permitted.');
                }
            }
            if ((int) $record['version'] !== $version) {
                throw new ConcurrentModificationException('Reload the current transcript request.');
            }
            $this->repository->changeTranscriptStatus($id, $status, $version);
            $this->audit->record($actor, 'transcript.' . $status, $id);
        });
    }
}
