<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlWebsiteFormRoutingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteFormFixture;

final class WebsiteInquiryAssignmentsTest extends TestCase
{
    private const array DUTIES = ['sis:website:forms:read', 'sis:website:forms:manage'];

    private function fixture(): array
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFormFixture.php';
        $f = new WebsiteFormFixture();
        $person = $f->sis->legacyPerson($f->sis->access->actor, 'Fictional', 'Operator')->id;
        $f->submit();
        return [$f, new EntityId($f->inbox->page()['items'][0]['id']), $person];
    }

    private function colleague(WebsiteFormFixture $f, string $name = 'Colleague', bool $scope = true): array
    {
        $s = $f->sis;
        $id = $s->user();
        $s->sql->update('users', ['sign_in_enabled' => 1], ['user_id' => $id->value]);
        $person = $s->legacyPerson($id, 'Fictional', $name)->id;
        $roles = new SqlAccessRepository($s->sql);
        foreach (self::DUTIES as $duty) {
            $permission = new EntityId($s->sql->one('sis_access_permissions', ['name' => $duty])['id']);
            $roles->assign(GrantKind::UserPermission, $id, $permission, true);
        }
        if ($scope) {
            new SqlWebsiteFormRoutingRepository($s->sql)->save('form', '1', 'contact', $id, true);
        }
        return [$id, $person];
    }

    private function reject(callable $run, string $type): void
    {
        try {
            $run();
            self::fail('Expected a rejected inquiry assignment.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }

    public function testClaimDelegateClearAndDeletePreserveEncryptedIntakeAndNeverCreatePeopleOrAcademicHistory(): void
    {
        [$f, $id, $person] = $this->fixture();
        [$colleague, $colleaguePerson] = $this->colleague($f);
        $s = $f->sis;
        $actor = $s->access->actor;
        $before = array_map($s->sql->rows(...), ['sis_website_form_inbox', 'sis_persons', 'sis_history_events',
            'sis_website_form_receipts', 'sis_access_user_permissions', 'sis_website_form_access']);
        $claim = $f->inbox->assign($id, 0, 0, $actor, $person);
        self::assertSame(1, $claim['version']);
        self::assertSame('Fictional Operator', $claim['name']);
        self::assertCount(1, $f->inbox->page(scope: 'mine')['items']);
        self::assertSame([], $f->inbox->page(scope: 'unassigned')['items']);
        $audits = $s->sql->rows('sis_audit');
        self::assertSame($claim, $f->inbox->assign($id, 1, 0, $actor, $person));
        self::assertSame($audits, $s->sql->rows('sis_audit'));
        $delegated = $f->inbox->assign($id, 1, 0, $colleague, $colleaguePerson);
        self::assertSame(2, $delegated['version']);
        self::assertSame([], $f->inbox->page(scope: 'mine')['items']);
        self::assertSame('Fictional Colleague', $f->inbox->show($id)['assignment']['name']);
        $clear = $f->inbox->assign($id, 2, 0, null, null);
        self::assertSame('Unassigned', $clear['name']);
        self::assertCount(1, $f->inbox->page(scope: 'unassigned')['items']);
        self::assertSame($before, array_map($s->sql->rows(...), ['sis_website_form_inbox', 'sis_persons',
            'sis_history_events', 'sis_website_form_receipts', 'sis_access_user_permissions',
            'sis_website_form_access']));
        $f->inbox->assign($id, 3, 0, $colleague, $colleaguePerson);
        $f->inbox->change($id, 0, 'close');
        self::assertFalse($f->inbox->show($id)['assignment']['can_assign']);
        self::assertSame('Fictional Colleague', $f->inbox->show($id)['assignment']['name']);
        $f->inbox->change($id, 1, 'delete');
        self::assertSame([], $s->sql->rows('sis_website_inquiry_assignments'));
    }

    public function testAssignmentAndInquiryVersionsAreIndependentAndNeitherStaleRequestCanOverwriteNewWork(): void
    {
        [$f, $id, $person] = $this->fixture();
        $actor = $f->sis->access->actor;
        $f->inbox->assign($id, 0, 0, $actor, $person);
        $this->reject(fn() => $f->inbox->assign($id, 0, 0, null, null), ConcurrentModificationException::class);
        $f->inbox->change($id, 0, 'close');
        $this->reject(fn() => $f->inbox->assign($id, 1, 1, null, null), ConcurrentModificationException::class);
        $f->inbox->change($id, 1, 'reopen');
        $this->reject(fn() => $f->inbox->assign($id, 1, 0, null, null), ConcurrentModificationException::class);
        self::assertSame(2, $f->inbox->assign($id, 1, 2, null, null)['version']);
    }

    #[DataProvider('ineligible')]
    public function testCandidateRequiresEnabledAccountCanonicalPersonBothDutiesAndExactScope(string $condition): void
    {
        [$f, $id] = $this->fixture();
        [$user, $person] = $this->colleague($f, scope: $condition !== 'scope');
        $s = $f->sis;
        if ($condition === 'disabled') {
            $s->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $user->value]);
        } elseif ($condition === 'person') {
            $person = EntityId::generate();
        } elseif (in_array($condition, ['read', 'manage'], true)) {
            $permission = $s->sql->one('sis_access_permissions', ['name' => 'sis:website:forms:' . $condition]);
            new SqlAccessRepository($s->sql)->assign(
                GrantKind::UserPermission,
                $user,
                new EntityId($permission['id']),
                false
            );
        }
        $this->reject(fn() => $f->inbox->assign($id, 0, 0, $user, $person), ValidationException::class);
        self::assertNull($f->records->assignment($id));
    }

    public static function ineligible(): array
    {
        return [['disabled'], ['person'], ['read'], ['manage'], ['scope']];
    }

    public function testDepartmentScopeIsRequiredEvenForAnExplicitFormGrantee(): void
    {
        [$f] = $this->fixture();
        $s = $f->sis;
        $department = EntityId::generate()->value;
        $s->sql->insert('sis_catalog_classifications', ['id' => $department, 'kind' => 'department',
            'code' => 'ADV', 'title' => 'Fictional advising', 'active' => 1]);
        $definition = \Domain\WebsiteForms\ValueObject\WebsiteFormDefinition::starter();
        $definition['department_id'] = $department;
        $f->definition($definition);
        $f->website->transition('1', 'submit');
        $f->website->transition('1', 'approve');
        new SqlWebsiteFormRoutingRepository($s->sql)->save('department', $department, '', $s->access->actor, true);
        $f->submit();
        $id = new EntityId($f->inbox->page()['items'][0]['id']);
        [$user, $person] = $this->colleague($f);
        $this->reject(fn() => $f->inbox->assign($id, 0, 0, $user, $person), ValidationException::class);
        new SqlWebsiteFormRoutingRepository($s->sql)->save('department', $department, '', $user, true);
        self::assertSame('Fictional Colleague', $f->inbox->assign($id, 0, 0, $user, $person)['name']);
    }

    public function testAssignmentsGrantNoAccessAndReboundAccountsAreNotAdoptedByMyQueue(): void
    {
        [$f, $id] = $this->fixture();
        [$user, $person] = $this->colleague($f);
        $f->inbox->assign($id, 0, 0, $user, $person);
        $s = $f->sis;
        new SqlWebsiteFormRoutingRepository($s->sql)->save('form', '1', 'contact', $user, false);
        self::assertSame('Assigned account unavailable', $f->inbox->page()['items'][0]['assignee_name']);
        self::assertSame([], $f->inbox->page(scope: 'unassigned')['items']);
        $s->access->actor = $user;
        $s->access->staff = false;
        $s->access->grants = self::DUTIES;
        self::assertSame([], $f->inbox->page(scope: 'mine')['items']);
        $this->reject(fn() => $f->inbox->show($id), RecordNotFoundException::class);
        new SqlWebsiteFormRoutingRepository($s->sql)->save('form', '1', 'contact', $user, true);
        $s->sql->update('sis_persons', ['owner_user_id' => $s->user()->value], ['id' => $person->value]);
        $s->legacyPerson($user, 'Replacement', 'Identity');
        self::assertSame([], $f->inbox->page(scope: 'mine')['items']);
        self::assertSame('Assigned account unavailable', $f->inbox->page()['items'][0]['assignee_name']);
        self::assertSame($person->value, $f->records->assignment($id)['assignee_person_id']);
    }

    public function testColleagueSearchFiltersScopeBeforeItsBoundAndKeepsNamesLiteral(): void
    {
        [$f, $id] = $this->fixture();
        for ($index = 0; $index < 23; $index++) {
            $this->colleague($f, 'Hidden colleague ' . $index, false);
        }
        [$first] = $this->colleague($f, 'Literal %_! colleague');
        [$second] = $this->colleague($f, 'Other colleague');
        self::assertSame([], $f->inbox->assignees($id, 'x')['items']);
        $rows = $f->inbox->assignees($id, 'colleague');
        self::assertSame([$first->value, $second->value], array_column($rows['items'], 'id'));
        self::assertNull($rows['next_after']);
        self::assertSame([$first->value], array_column($f->inbox->assignees($id, '%_!')['items'], 'id'));
        self::assertArrayNotHasKey('institutional_id', $rows['items'][0]);
        self::assertArrayNotHasKey('email', $rows['items'][0]);
    }

    public function testEligibleColleaguesHaveCompleteTwentyRowKeysetPages(): void
    {
        [$f, $id] = $this->fixture();
        $ids = [];
        for ($index = 0; $index < 24; $index++) {
            $ids[] = $this->colleague($f, 'Page colleague ' . $index)[0]->value;
        }
        $first = $f->inbox->assignees($id, 'Page colleague');
        self::assertSame(array_slice($ids, 0, 20), array_column($first['items'], 'id'));
        self::assertSame($ids[19], $first['next_after']);
        $last = $f->inbox->assignees($id, 'Page colleague', new EntityId($first['next_after']));
        self::assertSame(array_slice($ids, 20), array_column($last['items'], 'id'));
        self::assertNull($last['next_after']);
    }

    #[DataProvider('drifts')]
    public function testFinalChecksRollBackAssignmentAndAuditAfterChanges(string $change): void
    {
        [$f, $id, $person] = $this->fixture();
        [$target, $targetPerson] = $this->colleague($f);
        $s = $f->sis;
        $actor = $s->access->actor;
        $tables = ['sis_website_inquiry_assignments', 'sis_website_form_inbox', 'sis_website_form_access',
            'sis_audit', 'sis_persons', 'sis_access_user_permissions', 'sis_history_events'];
        $before = array_map($s->sql->rows(...), $tables);
        $audit = new class ($s->audit, function () use ($s, $id, $person, $actor, $target, $change): void {
            match ($change) {
                'actor' => $s->access->actor = $target,
                'actor_person' => $s->sql->update(
                    'sis_persons',
                    ['owner_user_id' => $target->value], ['id' => $person->value]
                ),
                'scope' => new SqlWebsiteFormRoutingRepository($s->sql)->save('form', '1', 'contact', $target, false),
                'authority' => $s->sql->execute(
                    'DELETE FROM sis_access_user_permissions WHERE user_id = ?',
                    [$actor->value]
                ),
                'source' => $s->sql->update('sis_website_form_inbox', ['version' => 2], ['id' => $id->value]),
                'audit' => throw new \RuntimeException('Fictional required audit failure.'),
            };
        }) implements AuditTrail {
            public function __construct(private AuditTrail $inner, private \Closure $change)
            {
            }
            public function forSubject(EntityId $id): array
            {
                return $this->inner->forSubject($id);
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->inner->record($actor, $action, $subject);
                ($this->change)();
            }
        };
        $this->reject(fn() => $f->inbox($audit)->assign($id, 0, 0, $target, $targetPerson), match ($change) {
            'source' => ConcurrentModificationException::class,
            'scope' => ValidationException::class,
            'audit' => \RuntimeException::class,
            default => AccessDeniedException::class,
        });
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
    }

    public static function drifts(): array
    {
        return [['actor'], ['actor_person'], ['scope'], ['authority'], ['source'], ['audit']];
    }
}
