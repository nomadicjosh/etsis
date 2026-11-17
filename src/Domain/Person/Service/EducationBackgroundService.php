<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\EducationBackgroundRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\ValueObject\EducationBackground;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

/** Person-owned education and admissions feedback share one protected profile history. */
final readonly class EducationBackgroundService
{
    public function __construct(
        private EducationBackgroundRepository $background,
        private PersonProfileRepository $rows,
        private ProfileLedger $history,
        private PersonProfileAccess $authorization,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array{entries:list<array<string,string>>,version:int} */
    public function view(EntityId $person): array
    {
        return $this->viewAs($person, false);
    }

    /** @return array{entries:list<array<string,string>>,version:int} */
    public function staffView(EntityId $person): array
    {
        return $this->viewAs($person, true);
    }

    /** @return array{entries:list<array<string,string>>,version:int} */
    private function viewAs(EntityId $expectedPerson, bool $staff): array
    {
        $actor = $this->authorization->actor();
        $person = $this->authorization->person($actor, $expectedPerson, $staff);
        $current = $this->background->current($person->id);
        $this->history->verifyEducation($person, $current);
        $this->authorization->person($actor, $expectedPerson, $staff);
        return $current?->facts() ?? ['entries' => [], 'version' => 0];
    }

    /** @param array<mixed> $entries */
    public function save(
        #[\SensitiveParameter] array $entries,
        int $version,
        #[\SensitiveParameter] string $reason
    ): void {
        $this->change($entries, $version, $reason);
    }

    /** @param array<mixed> $entries */
    public function staffSave(
        EntityId $person,
        #[\SensitiveParameter] array $entries,
        int $version,
        #[\SensitiveParameter] string $reason
    ): void {
        $this->change($entries, $version, $reason, $person);
    }

    public function review(
        EntityId $person,
        string $entryKey,
        string $status,
        int $version,
        #[\SensitiveParameter] string $feedback
    ): void {
        if (!in_array($status, ['reviewed', 'changes_requested'], true)) {
            throw new ValidationException([['field' => 'review_status', 'message' => 'Choose a review outcome.']]);
        }
        $this->change([], $version, $feedback, $person, $status, $entryKey);
    }

    /** @param array<mixed> $entries */
    private function change(
        #[\SensitiveParameter] array $entries,
        int $version,
        #[\SensitiveParameter] string $reason,
        ?EntityId $staffPerson = null,
        string $action = 'details_updated',
        string $entryKey = ''
    ): void {
        $actor = $this->authorization->actor();
        $staff = $staffPerson !== null;
        $person = $this->authorization->person($actor, $staffPerson, $staff);
        $review = $action !== 'details_updated';
        if ($review) {
            $this->reviewer($actor);
        }
        if ($version < 0 || $version === PHP_INT_MAX) {
            throw new ValidationException([['field' => 'version', 'message' => 'Reload the educational background.']]);
        }
        if (!$review && $version === 0 && trim($reason) === '') {
            $reason = $staff ? 'Educational background first recorded by staff.'
            : 'Educational background first supplied by the account owner.';
        }
        $reason = EducationBackground::reason($reason);
        $input = $review ? null : EducationBackground::fromInput($entries, $version + 1);
        $intent = ProfileLedger::intent([$version, $input?->facts(), $action, $entryKey, $reason, $staff]);
        $operation = DerivedIdentity::from($person->id, 'person.education:' . $actor->value . ':' . $intent);
        $this->transactions->run(function () use (
            $actor,
            $person,
            $staff,
            $review,
            $version,
            $reason,
            $input,
            $intent,
            $operation,
            $action,
            $entryKey
        ): void {
            if ($staff) {
                $this->authorization->lockStaffRoles();
            }
            $this->rows->lock($person->id);
            $currentPerson = $this->authorization->person($actor, $person->id, $staff);
            if ($review) {
                $this->reviewer($actor);
            }
            if ($this->history->educationReceipt($person->id, $operation, $actor, $intent) !== null) {
                $this->authorization->person($actor, $person->id, $staff);
                if ($review) {
                    $this->reviewer($actor);
                }
                return;
            }
            $before = $this->background->current($person->id);
            if (($before?->version ?? 0) !== $version) {
                throw new ConcurrentModificationException('The educational background changed. Reload before saving.');
            }
            if ($review && $before === null) {
                throw new ValidationException([['field' => 'entry_key', 'message' => 'Add an institution first.']]);
            }
            $details = array_map(static fn (array $row): array =>
                array_intersect_key($row, array_flip(EducationBackground::FIELDS)), $input?->entries ?? []);
            $next = $review ? $before->review($entryKey, $action, $reason)
                : EducationBackground::fromInput($details, $version + 1, $before);
            if (
                $this->history->recordEducation(
                    $currentPerson,
                    $before,
                    $next,
                    $operation,
                    $actor,
                    $intent,
                    $action,
                    $entryKey,
                    $reason
                )
            ) {
                $this->background->save($person->id, $next, $version);
                $this->audit->record($actor, 'person.education_' . $action, $person->id);
            }
            $this->authorization->person($actor, $person->id, $staff);
            if ($review) {
                $this->reviewer($actor);
            }
        });
    }

    private function reviewer(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:admissions:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The admissions reviewer changed.');
        }
    }
}
