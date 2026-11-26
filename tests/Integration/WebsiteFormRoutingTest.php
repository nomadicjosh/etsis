<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlWebsiteFormRoutingRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteFormFixture;

final class WebsiteFormRoutingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFormFixture.php';
    }

    private function department(WebsiteFormFixture $f, string $code): string
    {
        $id = EntityId::generate()->value;
        $f->sis->sql->insert('sis_catalog_classifications', ['id' => $id, 'kind' => 'department',
            'code' => $code, 'title' => 'Fictional ' . $code, 'active' => 1]);
        return $id;
    }

    private function route(WebsiteFormFixture $f, ?string $department): void
    {
        $definition = WebsiteFormDefinition::starter();
        $definition['department_id'] = $department;
        $f->definition($definition);
        $f->website->transition('1', 'submit');
        $f->website->transition('1', 'approve');
    }

    private function admin(WebsiteFormFixture $f): void
    {
        $permission = $f->sis->sql->one('sis_access_permissions', ['name' => 'admin:access:manage']);
        (new SqlAccessRepository($f->sis->sql))->assign(
            GrantKind::UserPermission,
            $f->sis->access->actor,
            new EntityId($permission['id']),
            true
        );
    }

    public function testDepartmentRestrictionCannotBeBypassedByGlobalReadManageOrAnIndividualFormGrant(): void
    {
        $f = new WebsiteFormFixture();
        $dept = $this->department($f, 'ADMISSIONS');
        $other = $this->department($f, 'FINANCE');
        $this->route($f, $dept);
        $f->submit();
        $id = new EntityId($f->sis->sql->rows('sis_website_form_inbox')[0]['id']);
        $grants = new SqlWebsiteFormRoutingRepository($f->sis->sql);
        $grants->save('department', $other, '', $f->sis->access->actor, true);
        self::assertSame([], $f->inbox->page()['items']);
        foreach (['read', 'delete'] as $action) {
            try {
                $action === 'read' ? $f->inbox->show($id) : $f->inbox->change($id, 0, 'delete');
                self::fail('A form/global duty bypassed department membership.');
            } catch (RecordNotFoundException) {
                self::assertCount(1, $f->sis->sql->rows('sis_website_form_inbox'));
            }
        }
        $grants->save('department', $dept, '', $f->sis->access->actor, true);
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:website:forms:read'];
        self::assertSame($f->answers, $f->inbox->show($id)['answers']);
        $this->expectException(AccessDeniedException::class);
        $f->inbox->change($id, 0, 'delete');
    }

    public function testReroutingNewRepliesPreservesOriginalDepartmentAndRevocationHidesEvenMetadata(): void
    {
        $f = new WebsiteFormFixture();
        $first = $this->department($f, 'FIRST');
        $second = $this->department($f, 'SECOND');
        $this->route($f, $first);
        $f->submit();
        $this->route($f, $second);
        $f->submit();
        $this->admin($f);
        $routing = $f->website->formRouting();
        $routing->assign('department', $first, '', $f->sis->access->actor, true, null);
        $list = $f->inbox->page();
        self::assertCount(1, $list['items']);
        self::assertSame($first, $list['items'][0]['department_id']);
        $member = $routing->members('department', $first, '')['items'][0];
        $routing->assign('department', $first, '', $f->sis->access->actor, false, new EntityId($member['id']));
        self::assertSame([], $f->inbox->page()['items']);
        $this->expectException(RecordNotFoundException::class);
        $f->inbox->show(new EntityId($list['items'][0]['id']));
    }

    public function testUnassignedFormRequiresItsOwnExactPageAndFormGrantAndFiltersBeforePaging(): void
    {
        $f = new WebsiteFormFixture();
        $s = $f->sis;
        $f->submit();
        $visible = $s->sql->rows('sis_website_form_inbox')[0];
        for ($i = 0; $i < 24; $i++) {
            $s->sql->insert('sis_website_form_inbox', array_replace($visible, ['id' => EntityId::generate()->value,
                'page_id' => 'other-page', 'form_key' => 'other_form']));
        }
        $list = $f->inbox->page();
        self::assertSame([$visible['id']], array_column($list['items'], 'id'));
        self::assertNull($list['next_after']);
        $records = new SqlWebsiteFormRoutingRepository($s->sql);
        $records->save('form', '1', 'contact', $s->access->actor, false);
        $records->save('form', 'other-page', 'contact', $s->access->actor, true);
        self::assertSame([], $f->inbox->page()['items']);
    }

    public function testAccessChangesRequireIndependentAdministrationAndFreshMembershipReceipt(): void
    {
        $f = new WebsiteFormFixture();
        $department = $this->department($f, 'CONTACT');
        $routing = $f->website->formRouting();
        try {
            $routing->assign('department', $department, '', $f->sis->access->actor, true, null);
            self::fail('A form read/manage duty granted department membership.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sis->sql->rows('sis_website_form_access', ['scope_kind' => 'department']));
        }
        $this->admin($f);
        self::assertSame('1', $routing->choices('page', '')['items'][0]['id']);
        self::assertSame('contact', $routing->forms('1')['items'][0]['key']);
        $routing->assign('department', $department, '', $f->sis->access->actor, true, null);
        $row = $routing->members('department', $department, '')['items'][0];
        $routing->assign('department', $department, '', $f->sis->access->actor, false, new EntityId($row['id']));
        $routing->assign('department', $department, '', $f->sis->access->actor, true, null);
        $this->expectException(ConcurrentModificationException::class);
        $routing->assign('department', $department, '', $f->sis->access->actor, false, new EntityId($row['id']));
    }

    public function testPermissionRevocationAndDisabledAccountsImmediatelyBlockDepartmentMembers(): void
    {
        foreach (['permission', 'disabled', 'department'] as $mode) {
            $f = new WebsiteFormFixture();
            $dept = $this->department($f, 'PRIVATE');
            $this->route($f, $dept);
            (new SqlWebsiteFormRoutingRepository($f->sis->sql))->save(
                'department',
                $dept,
                '',
                $f->sis->access->actor,
                true
            );
            $f->submit();
            $id = new EntityId($f->inbox->page()['items'][0]['id']);
            if ($mode === 'permission') {
                $permission = $f->sis->sql->one('sis_access_permissions', ['name' => 'sis:website:forms:read']);
                (new SqlAccessRepository($f->sis->sql))->assign(
                    GrantKind::UserPermission,
                    $f->sis->access->actor,
                    new EntityId($permission['id']),
                    false
                );
            } elseif ($mode === 'disabled') {
                $f->sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $f->sis->access->actor->value]);
            } else {
                $f->sis->sql->update('sis_catalog_classifications', ['active' => 0], ['id' => $dept]);
            }
            try {
                $f->inbox->show($id);
                self::fail('A revoked/disabled source exposed an inquiry.');
            } catch (AccessDeniedException | RecordNotFoundException) {
                self::assertCount(1, $f->sis->sql->rows('sis_website_form_inbox'));
            }
        }
    }

    public function testMembershipRevokedDuringRequiredReadAuditCannotReturnAnswers(): void
    {
        $f = new WebsiteFormFixture();
        $f->submit();
        $id = new EntityId($f->inbox->page()['items'][0]['id']);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f): void {
            (new SqlWebsiteFormRoutingRepository($f->sis->sql))->save(
                'form',
                '1',
                'contact',
                $f->sis->access->actor,
                false
            );
        });
        $this->expectException(RecordNotFoundException::class);
        $f->inbox($audit)->show($id);
    }
    public function testScopeRevokedDuringDeleteAuditRollsBackTheDeletionAndMembershipChange(): void
    {
        $f = new WebsiteFormFixture();
        $f->submit();
        $id = new EntityId($f->inbox->page()['items'][0]['id']);
        $before = $f->sis->sql->rows('sis_website_form_inbox');
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f): void {
            (new SqlWebsiteFormRoutingRepository($f->sis->sql))->save(
                'form',
                '1',
                'contact',
                $f->sis->access->actor,
                false
            );
        });
        try {
            $f->inbox($audit)->change($id, 0, 'delete');
            self::fail('A final scope change committed an inquiry deletion.');
        } catch (RecordNotFoundException) {
            self::assertSame($before, $f->sis->sql->rows('sis_website_form_inbox'));
            self::assertCount(1, $f->inbox->page()['items']);
        }
    }
    public function testAccessSetupRetainsInquiriesAcrossEmptyInvalidAndRemovedPageDrafts(): void
    {
        $f = new WebsiteFormFixture();
        $f->submit();
        $this->admin($f);
        $routing = $f->website->formRouting();
        foreach ([null, 'invalid draft'] as $data) {
            $f->sis->sql->update('pages', ['data' => $data], ['id' => '1']);
            self::assertSame('contact', $routing->forms('1')['items'][0]['key']);
        }
        $f->sis->sql->execute('DELETE FROM pages WHERE id = ?', ['1']);
        self::assertSame('1', $routing->choices('page', '')['items'][0]['id']);
        self::assertSame('contact', $routing->forms('1')['items'][0]['key']);
        $f->website->draft('2');
        $f->sis->sql->update('pages', ['data' => null], ['id' => '2']);
        self::assertSame([], $routing->forms('2')['items']);
    }
}
