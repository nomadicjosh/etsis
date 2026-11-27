<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MailTransport;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\EmailVerificationPolicy;
use Domain\User\Service\EmailVerificationService;
use Infrastructure\Persistence\Repository\SqlEmailVerificationRepository;
use Infrastructure\Persistence\Repository\EventSourcedUserRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;

final class EmailVerificationFixture implements EmailVerificationPolicy, MailTransport
{
    public \SisFixture $sis;
    public EntityId $owner;
    public EntityId $person;
    public EventSourcedUserRepository $users;
    public SqlEmailVerificationRepository $records;
    public bool $required = true;
    public bool $enabled = true;
    public bool $uncertain = false;
    public array $sent = [];
    public ?\Closure $duringSend = null;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = new \SisFixture();
        $person = $s->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Applicant',
            'proof@example.invalid'
        );
        $this->person = $person->id;
        $this->owner = $person->ownerUserId;
        $links = new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator());
        $this->users = new EventSourcedUserRepository(
            \EventStoreFixture::framework($s->connection->queryBuilder()),
            new \Infrastructure\Projection\ExpressiveDbalUserProjection(
                $s->connection->queryBuilder(),
                new SqlAccessRepository($s->sql),
                $links
            ),
            $s->connection->queryBuilder(),
            \EventStoreFixture::history($s->connection->queryBuilder())
        );
        $this->records = new SqlEmailVerificationRepository($s->sql);
        $s->access->actor = $this->owner;
        $s->access->staff = false;
        $s->access->grants = [];
    }

    public function service(?AuditTrail $audit = null): EmailVerificationService
    {
        $s = $this->sis;
        return new EmailVerificationService(
            $this->records,
            $this->users,
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->admissions,
            new SqlAccessRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
            $this,
            $this
        );
    }

    public function requiredForApplications(): bool
    {
        return $this->required;
    }
    public function deliveryEnabled(): bool
    {
        return $this->enabled;
    }
    public function origin(): string
    {
        return 'https://campus.example.invalid';
    }
    public function assertReady(): void
    {
    }
    public function send(EntityId $delivery, string $recipient, string $subject, string $body): void
    {
        $this->sent[] = [$delivery->value, $recipient, $subject, $body];
        ($this->duringSend ?? static function (): void {
        })();
        if ($this->uncertain) {
            throw new \RuntimeException('Fictional uncertain transport.');
        }
    }
    public function changeEmail(string $email): void
    {
        $this->changeUser(static fn (\Domain\User\User $user) => $user->changeEmailAddress(
            new \Qubus\ValueObjects\Web\EmailAddress($email)
        ));
    }
    public function changeUser(\Closure $change): void
    {
        $this->sis->transactions->run(function () use ($change): void {
            $user = $this->users->loadAggregateRoot(new \Domain\User\ValueObject\UserId($this->owner->value));
            $change($user);
            $this->users->saveProfile($user, new \Domain\Shared\EventSourcing\EventContext(
                $this->owner,
                $this->sis->clock->now(),
                EntityId::generate(),
                reason: 'Fictional credential change.'
            ));
        });
    }
    public function audit(\Closure $during): \Domain\Audit\Repository\AuditTrail
    {
        return new class ($this->sis->audit, $during) implements \Domain\Audit\Repository\AuditTrail {
            public function __construct(private \Domain\Audit\Repository\AuditTrail $inner, private \Closure $during)
            {
            }
            public function forSubject(EntityId $id): array
            {
                return $this->inner->forSubject($id);
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->inner->record($actor, $action, $subject);
                ($this->during)($action);
            }
        };
    }
    public function form(): EntityId
    {
        $s = $this->sis;
        $s->access->staff = true;
        $forms = new \Domain\Admissions\Service\ApplicationFormService(
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository($s->sql),
            $s->admissionsService(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->submissionService(verification: $this->service())
        );
        $form = $forms->save(null, $s->program, 'Fictional application', [
            ['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true],
        ], null);
        $forms->publish($form->id, 0);
        $s->access->staff = false;
        return $form->id;
    }
    public function token(): string
    {
        $row = $this->records->get($this->owner);
        $notice = json_decode($this->sis->dropCipher->open($row['sealed_notice']), true, flags: JSON_THROW_ON_ERROR);
        preg_match('/#([a-f0-9]{64})\z/', $notice['body'], $match);
        return $match[1];
    }

    public function retention(): \Domain\User\Service\EmailVerificationRetention
    {
        return new \Domain\User\Service\EmailVerificationRetention(
            $this->records,
            $this->sis->transactions,
            $this->sis->clock
        );
    }
}
