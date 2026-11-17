<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Access\Service\BootstrapAccess;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdmissionCaseworkFixture;

final class AdmissionHandoffTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
    }
    private function fixtureWithAccess(): array
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $roles = new SqlAccessRepository($s->sql);
        new BootstrapAccess($roles, $s->transactions, $s->audit)->assignFirstAdministrator($s->access->actor);
        $permission = new EntityId($s->sql->one('sis_access_permissions', ['name' => 'sis:admissions:manage'])['id']);
        return [$f, $roles, $permission];
    }
    public function testSearchAndAssignmentUseEnabledAccountsWithCurrentAdmissionsAuthority(): void
    {
        [$f, $roles, $permission] = $this->fixtureWithAccess();
        $s = $f->campus->sis;
        $target = $s->user();
        $s->legacyPerson($target, 'Eligible', 'Colleague');
        $roles->assign(GrantKind::UserPermission, $target, $permission, true);
        $other = $s->user();
        $s->legacyPerson($other, 'Eligible', 'Unprivileged');
        $disabled = $s->user();
        $s->legacyPerson($disabled, 'Eligible', 'Disabled');
        $roles->assign(GrantKind::UserPermission, $disabled, $permission, true);
        $s->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $disabled->value]);
        self::assertTrue($f->service->assignees('')['search_required']);
        self::assertSame([$target->value], array_column($f->service->assignees('Eligible')['items'], 'id'));
        self::assertSame([$target->value], array_column($f->service->assignees('Eligible Colleague')['items'], 'id'));
        self::assertSame([], $f->service->assignees('%_')['items']);
        $f->service->save($f->application, ['private_notes' => 'Fictional handoff.'], 0, 'selected', $target);
        self::assertSame($target->value, $f->service->view($f->application)['case']['assigned_to']);
        self::assertSame([], $s->sql->rows('sis_admission_updates'));
    }
    public function testPermissionRevocationAfterSearchRejectsTheHandoffWithoutLosingDraftData(): void
    {
        [$f, $roles, $permission] = $this->fixtureWithAccess();
        $s = $f->campus->sis;
        $target = $s->user();
        $s->legacyPerson($target, 'Eligible', 'Colleague');
        $roles->assign(GrantKind::UserPermission, $target, $permission, true);
        self::assertCount(1, $f->service->assignees('Eligible')['items']);
        $roles->assign(GrantKind::UserPermission, $target, $permission, false);
        try {
            $f->service->save(
                $f->application,
                ['private_notes' => 'Fictional unsaved handoff note.'],
                0,
                'selected',
                $target
            );
            self::fail('A revoked admissions assignee was accepted.');
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows('sis_admission_cases'));
            self::assertSame([], $s->sql->rows('sis_admission_updates'));
        }
    }
    public function testBulkPermissionResultsMatchSingleChecksIncludingSuperAndDisabledAccounts(): void
    {
        [$f, $roles, $permission] = $this->fixtureWithAccess();
        $s = $f->campus->sis;
        $direct = $s->user();
        $roles->assign(GrantKind::UserPermission, $direct, $permission, true);
        $roleUser = $s->user();
        $roles->assign(GrantKind::UserRole, $roleUser, $roles->roleNamed('admin')->id, true);
        $disabled = $s->user();
        $roles->assign(GrantKind::UserPermission, $disabled, $permission, true);
        $s->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $disabled->value]);
        $users = [$s->access->actor, $direct, $roleUser, $disabled, $s->user()];
        $allowed = array_flip($roles->allowedUsers($users, 'sis:admissions:manage'));
        foreach ($users as $user) {
            self::assertSame($roles->allows($user->value, 'sis:admissions:manage'), isset($allowed[$user->value]));
        }
        self::assertArrayHasKey($s->access->actor->value, $allowed);
        self::assertArrayHasKey($direct->value, $allowed);
        self::assertArrayNotHasKey($disabled->value, $allowed);
        self::assertSame([], $roles->allowedUsers([], 'sis:admissions:manage'));
    }
    public function testCandidateFilteringHappensBeforeTheTwentyAccountPageBound(): void
    {
        [$f, $roles, $permission] = $this->fixtureWithAccess();
        $s = $f->campus->sis;
        $expected = [];
        for ($index = 0; $index < 24; $index++) {
            $user = $s->user();
            $s->legacyPerson($user, 'Fictional', 'Colleague');
            $roles->assign(GrantKind::UserPermission, $user, $permission, true);
            $expected[] = $user->value;
            $s->legacyPerson($s->user(), 'Fictional', 'Colleague Unprivileged');
        }
        $first = $f->service->assignees('Colleague');
        self::assertCount(20, $first['items']);
        $second = $f->service->assignees('Colleague', new EntityId($first['next_after']));
        self::assertCount(4, $second['items']);
        self::assertNull($second['next_after']);
        $actual = array_column([...$first['items'], ...$second['items']], 'id');
        sort($expected);
        self::assertSame($expected, $actual);
        self::assertSame(['id', 'given_name', 'family_name', 'institutional_id'], array_keys($first['items'][0]));
    }
    public function testActorRevocationDetectedByFreshBatchCheckPreventsAssignment(): void
    {
        $permissions = $this->createMock(\Domain\Access\Repository\AccessRepository::class);
        $permissions->expects(self::once())->method('lock');
        $permissions->expects(self::once())->method('allowedUsers')->willReturn([]);
        $f = new AdmissionCaseworkFixture(permissions: $permissions);
        $target = $f->campus->sis->user();
        $f->campus->sis->legacyPerson($target, 'Fictional', 'Colleague');
        try {
            $f->service->save($f->application, [], 0, 'selected', $target);
            self::fail('Assignment ignored revoked actor permissions.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame([], $f->campus->sis->sql->rows('sis_admission_cases'));
        }
    }
}
