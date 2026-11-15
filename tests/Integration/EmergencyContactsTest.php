<?php

declare(strict_types=1);

use Domain\Person\Service\EmergencyContactsService;
use Domain\Person\Service\PersonProfileAccess;
use Domain\Person\ValueObject\EmergencyContacts;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Infrastructure\Persistence\Repository\SqlEmergencyContactsRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/CampusFixture.php';

final class EmergencyContactsTest extends TestCase
{
    private function contact(string $name = 'Fictional Relative', string $phone = '001234'): array
    {
        return ['name' => $name, 'relationship' => 'Relative', 'phone' => $phone, 'email' => 'relative@example.invalid'];
    }
    private function service(CampusFixture $c, ?\Domain\Audit\Repository\AuditTrail $audit = null): EmergencyContactsService
    {
        $s = $c->sis;
        return new EmergencyContactsService(new SqlEmergencyContactsRepository($s->sql, $c->cipher),
            new SqlPersonProfileRepository($s->sql), $s->profileLedger(),
            new PersonProfileAccess(new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
                $s->admissions, new SqlAccessRepository($s->sql), $s->access),
            $s->transactions, $audit ?? $s->audit);
    }
    public function testContactsArePersonOwnedOrderedProtectedFactsWithoutCreatingAccountsForExternalContacts(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $people = $s->sql->rows('sis_persons');
        $users = $s->sql->rows('users');
        self::assertSame(['contacts' => [], 'version' => 0], $c->emergency->view($person));
        self::assertSame([], $s->sql->rows('sis_history_events'));
        $first = $this->contact();
        $second = $this->contact('Fictional Friend', '005678');
        $c->emergency->save([$first, $second], 0);
        self::assertSame([$first, $second], $c->emergency->view($person)['contacts']);
        $before = $s->sql->rows('sis_history_events');
        $c->emergency->save([$first, $second], 1);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $c->emergency->save([$second, $first], 1);
        self::assertSame([$second, $first], $c->emergency->view($person)['contacts']);
        $c->emergency->save([], 2);
        self::assertSame(['contacts' => [], 'version' => 3], $c->emergency->view($person));
        $c->emergency->save([$first, $second], 0);
        self::assertSame([], $c->emergency->view($person)['contacts']);
        self::assertSame($people, $s->sql->rows('sis_persons'));
        self::assertSame($users, $s->sql->rows('users'));
        foreach (['sis_person_emergency_contacts', 'sis_history_events', 'sis_history_operations', 'sis_audit'] as $table) {
            self::assertStringNotContainsString('Fictional Relative', json_encode($s->sql->rows($table), JSON_THROW_ON_ERROR));
        }
        $stream = $s->sql->rows('sis_history_streams', ['stream_type' => 'person_profile']);
        self::assertCount(1, $stream);
        self::assertSame(4, $s->profileLedger()->verify($s->admissions->person($person)));
    }
    public function testContactsShareTheExistingPersonStreamWithoutReplacingOrdinaryContactOrIdentityFacts(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $c->contacts->save('Preferred', '000999', 'Fictional address', 0);
        $c->emergency->save([$this->contact()], 0);
        $s->admissionsService()->updateInstitutionalId($person, 'SAME-PERSON', 0);
        self::assertSame('000999', $c->contacts->view($person)['phone']);
        self::assertSame('001234', $c->emergency->view($person)['contacts'][0]['phone']);
        self::assertSame(4, $s->profileLedger()->verify($s->admissions->person($person)));
        $this->expectException(ConcurrentModificationException::class);
        $c->emergency->save([$this->contact('Competing')], 0);
    }
    public function testStaffRequirePersonManagementAndOwnerRoutesNeverBorrowThatAuthority(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $s->access->staff = false;
        $s->access->grants = ['admin:edit:user'];
        try {
            $c->emergency->staffSave($person, [$this->contact()], 0);
            self::fail('Credential administration authorized contact editing.');
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows('sis_history_events'));
        }
        $s->access->grants = ['sis:person:manage'];
        $c->emergency->staffSave($person, [$this->contact()], 0);
        self::assertCount(1, $c->emergency->staffView($person)['contacts']);
        $this->expectException(AccessDeniedException::class);
        $c->emergency->view($person);
    }
    public function testSuperPersonProtectionIsPreservedForStaffEmergencyContactAccess(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $roles = new SqlAccessRepository($s->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole,
            $s->admissions->person($person)->ownerUserId, $roles->roleNamed('super')->id, true);
        $this->expectException(AccessDeniedException::class);
        $c->emergency->staffSave($person, [$this->contact()], 0);
    }
    public function testAuditFailureAndRevokedAuthorityRollBackEventsProjectionHeadsAndReceipts(): void
    {
        foreach (['audit', 'permission'] as $case) {
            $c = new CampusFixture();
            $s = $c->sis;
            $person = $s->student($s->user())->personId;
            $s->access->staff = false;
            $s->access->grants = ['sis:person:manage'];
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($case, $s): void {
                if ($case === 'audit') throw new RuntimeException('Fictional required audit failure');
                $s->access->grants = [];
            });
            try {
                $this->service($c, $audit)->staffSave($person, [$this->contact()], 0);
                self::fail('Accepted a partially authorized change.');
            } catch (RuntimeException | AccessDeniedException) {
                foreach (['sis_person_emergency_contacts', 'sis_history_events', 'sis_history_operations', 'sis_history_streams'] as $table) {
                    self::assertSame([], $s->sql->rows($table));
                }
            }
        }
    }
    public function testMissingAlteredOrUntrackedProjectionsCannotBecomeNewAuthoritativeFacts(): void
    {
        foreach (['missing', 'altered', 'untracked'] as $case) {
            $c = new CampusFixture();
            $s = $c->sis;
            $person = $s->student()->personId;
            if ($case !== 'untracked') $c->emergency->save([$this->contact()], 0);
            $repo = new SqlEmergencyContactsRepository($s->sql, $c->cipher);
            if ($case === 'missing') {
                $q = $s->sql->quote(...);
                $s->sql->execute('DELETE FROM ' . $q('sis_person_emergency_contacts'));
            } elseif ($case === 'untracked') {
                $repo->save($person, EmergencyContacts::fromInput([$this->contact()], 1), 0);
            } else {
                $s->sql->update('sis_person_emergency_contacts', ['encrypted_contacts' => $c->cipher->seal(json_encode([
                    $this->contact(phone: '1234')
                ], JSON_THROW_ON_ERROR))], ['person_id' => $person->value]);
            }
            $before = $s->sql->rows('sis_history_events');
            foreach (['read', 'write'] as $action) {
                try {
                    $action === 'read' ? $c->emergency->view($person) : $c->emergency->save([], 1);
                    self::fail('Accepted inconsistent emergency contact facts.');
                } catch (CorruptHistoryException) {
                    self::assertSame($before, $s->sql->rows('sis_history_events'));
                }
            }
        }
    }
    public function testInvalidAndExcessiveOrRepeatedContactsAreRejectedWithoutWrites(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $s->student();
        foreach ([array_fill(0, 6, $this->contact()), [$this->contact(), $this->contact()],
            [['name' => 'Fictional', 'phone' => '']], [['name' => 'Fictional', 'phone' => '555', 'email' => 'invalid']],
            [['name' => "Fictional\nName", 'phone' => '555']]] as $input) {
            try {
                $c->emergency->save($input, 0);
                self::fail('Invalid emergency contacts were accepted.');
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows('sis_history_events'));
                self::assertSame([], $s->sql->rows('sis_person_emergency_contacts'));
            }
        }
    }
}
