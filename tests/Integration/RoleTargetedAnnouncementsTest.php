<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Service\{CampusAnnouncementService, CampusContentService};
use Domain\Portal\ValueObject\{ContentAudience, PublicationWindow};
use Domain\Shared\Exception\{
AccessDeniedException,
ConcurrentModificationException,
RecordNotFoundException,
ValidationException
};
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\{
SqlAnnouncementRepository,
SqlContentAudienceRepository,
SqlPortalRepository
};
use Infrastructure\Portal\SymfonyContentMarkup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoleTargetedAnnouncementsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function role(CampusFixture $c, string $name = 'Fictional staff'): EntityId
    {
        $id = EntityId::generate();
        $c->sis->sql->insert('sis_access_roles', ['id' => $id->value, 'name' => $name,
            'description' => 'Fictional role definition.', 'is_system' => 0]);
        return $id;
    }

    private function assign(CampusFixture $c, EntityId $user, EntityId $role): void
    {
        $c->sis->sql->insert('sis_access_user_roles', ['id' => EntityId::generate()->value,
            'user_id' => $user->value, 'role_id' => $role->value]);
    }

    private function draft(CampusFixture $c, array $roles): EntityId
    {
        return $c->content->save(
            null,
            'news',
            'fictional-' . strtolower(EntityId::generate()->value),
            'Fictional role-targeted notice',
            'Private fictional announcement.',
            'roles',
            null,
            'plain',
            $roles
        );
    }

    private function announcements(CampusFixture $c, ?AuditTrail $audit = null): CampusAnnouncementService
    {
        $s = $c->sis;
        return new CampusAnnouncementService(
            new SqlAnnouncementRepository($s->sql, $s->clock),
            new SqlPortalRepository($s->sql, $s->clock),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new SymfonyContentMarkup()
        );
    }

    private function editor(CampusFixture $c, AuditTrail $audit): CampusContentService
    {
        $s = $c->sis;
        return new CampusContentService(
            new SqlPortalRepository($s->sql, $s->clock),
            $c->repository,
            $c->posts,
            $s->access,
            $s->transactions,
            $audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($c->cipher, $s->clock),
            new SymfonyContentMarkup(),
            new SqlContentAudienceRepository($s->sql)
        );
    }

    private function confirm(CampusContentService $editor, EntityId $post, array $review): array
    {
        return $editor->confirm(
            $post,
            new EntityId($review['operation_id']),
            'approve',
            $review['revision'],
            new PublicationWindow(null, null),
            null,
            $review['review_token']
        );
    }

    public function testAnySelectedCurrentRoleControlsEveryReaderWithoutASuperOrCapabilityBypass(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $otherRole = $this->role($c, 'Fictional faculty');
        $post = $this->draft($c, [$otherRole->value, $role->value]);
        $code = $c->content->get($post)['code'];
        $expectedRoles = [$role->value, $otherRole->value];
        sort($expectedRoles);
        self::assertSame($expectedRoles, array_column($c->content->get($post)['audience_roles'], 'id'));
        $c->publish($post, true, 0);
        $service = $this->announcements($c);
        $before = \EventStoreFixture::historyDigest($c->sis->sql);
        $reader = $c->sis->user();
        $this->assign($c, $reader, $otherRole);
        $c->sis->access->actor = $reader;
        $c->sis->access->staff = false;
        self::assertSame([$post->value], array_column($service->page()['items'], 'id'));
        self::assertArrayNotHasKey('audience_roles', $service->detail($post));
        self::assertSame([$post->value], array_column($c->service->news(true), 'id'));
        $article = $c->service->article($code, true);
        self::assertSame($post->value, $article['id']);
        self::assertArrayNotHasKey('owner_user_id', $article);
        self::assertArrayNotHasKey('review_note', $article);
        self::assertArrayNotHasKey('audience_roles', $article);
        self::assertSame($post->value, $c->posts->published($code)->id->value);
        self::assertSame([], $c->service->news());
        $unmatched = $c->sis->user();
        $super = new EntityId($c->sis->sql->one('sis_access_roles', ['name' => 'super'])['id']);
        $this->assign($c, $unmatched, $super);
        $c->sis->access->actor = $unmatched;
        $c->sis->access->staff = true;
        self::assertSame([], $service->page(false)['items']);
        self::assertSame([], $c->service->news(true));
        foreach (
            [fn() => $service->detail($post), fn() => $service->read($post, 1),
            fn() => $service->delete($post, 1), fn() => $c->service->article($code),
            fn() => $c->service->article($code, true), fn() => $c->posts->published($code)] as $read
        ) {
            try {
                $read();
                self::fail('An unmatched or public reader received a role-targeted announcement.');
            } catch (RecordNotFoundException) {
                self::assertTrue(true);
            }
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($c->sis->sql));
    }

    public function testRoleRemovalRevokesExistingDetailsReadingDeletionAndPagingWithoutRecreatingState(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $post = $this->draft($c, [$role->value]);
        $c->publish($post, true, 0);
        $reader = $c->sis->user();
        $this->assign($c, $reader, $role);
        $c->sis->access->actor = $reader;
        $service = $this->announcements($c);
        $service->read($post, 1);
        $read = $c->sis->sql->rows('sis_announcement_reads');
        $c->sis->sql->execute(
            'DELETE FROM sis_access_user_roles WHERE user_id = ? AND role_id = ?',
            [$reader->value, $role->value]
        );
        self::assertSame([], $service->page(false)['items']);
        try {
            $service->page(false, $post);
            self::fail('A now-ineligible cursor was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        foreach (
            [fn() => $service->detail($post), fn() => $service->read($post, 1),
            fn() => $service->delete($post, 1)] as $readDenied
        ) {
            try {
                $readDenied();
                self::fail('An earlier open notice preserved audience authority.');
            } catch (RecordNotFoundException) {
                self::assertSame($read, $c->sis->sql->rows('sis_announcement_reads'));
            }
        }
        $this->assign($c, $reader, $role);
        self::assertFalse($service->detail($post)['unread']);
        $service->delete($post, 1);
        self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
        self::assertSame([], $service->page(false)['items']);
    }

    public function testFilteringOccursBeforePagingAndMultipleMatchingRolesDoNotDuplicateNews(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $second = $this->role($c, 'Fictional second role');
        $unmatched = $this->role($c, 'Fictional other role');
        $visible = [];
        for ($i = 0; $i < 23; $i++) {
            foreach ([[$role->value, $second->value], [$unmatched->value]] as $roles) {
                $post = $this->draft($c, $roles);
                $c->publish($post, true, 0);
                if ($roles[0] === $role->value) {
                    $visible[] = $post->value;
                }
            }
        }
        $reader = $c->sis->user();
        $this->assign($c, $reader, $role);
        $this->assign($c, $reader, $second);
        $c->sis->access->actor = $reader;
        $service = $this->announcements($c);
        $first = $service->page(false);
        self::assertCount(20, $first['items']);
        $last = $service->page(false, new EntityId($first['next_after']));
        self::assertCount(3, $last['items']);
        self::assertNull($last['next_after']);
        $ids = array_merge(array_column($first['items'], 'id'), array_column($last['items'], 'id'));
        sort($ids);
        sort($visible);
        self::assertSame($visible, $ids);
    }

    public function testChangedTargetsInvalidateAReviewAndOriginalAcceptedRetriesPreserveLaterDrafts(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $other = $this->role($c, 'Fictional later role');
        $post = $this->draft($c, [$role->value]);
        $r = $c->content->review(
            $post,
            'approve',
            $c->content->get($post)['revision'],
            new PublicationWindow(null, null),
            null
        );
        $targets = new SqlContentAudienceRepository($c->sis->sql);
        $targets->replace($post, [['id' => $other->value, 'name' => 'Fictional later role']]);
        try {
            $this->confirm($c->content, $post, $r);
            self::fail('A reviewed role selection was substituted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('draft', $c->content->get($post)['status']);
            self::assertSame([], $c->sis->sql->rows('sis_content_operations'));
        }
        $r = $c->content->review(
            $post,
            'approve',
            $c->content->get($post)['revision'],
            new PublicationWindow(null, null),
            null
        );
        $receipt = $this->confirm($c->content, $post, $r);
        $saved = $c->content->get($post);
        $c->content->save(
            $post,
            'news',
            $saved['code'],
            'Later fictional text',
            'Later fictional wording.',
            'campus',
            1
        );
        self::assertSame($receipt, $this->confirm($c->content, $post, $r));
        self::assertSame('draft', $c->content->get($post)['status']);
        self::assertSame([], $targets->selected($post));
    }

    public function testDeletedRoleRetainsItsSelectedIdentityAndCannotBeReplacedByAnEqualName(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $post = $this->draft($c, [$role->value]);
        $c->publish($post, true, 0);
        $c->sis->sql->execute('DELETE FROM sis_access_roles WHERE id = ?', [$role->value]);
        self::assertSame(
            [['id' => $role->value, 'name' => 'Fictional staff']],
            $c->content->get($post)['audience_roles'],
        );
        $replacement = $this->role($c);
        $reader = $c->sis->user();
        $this->assign($c, $reader, $replacement);
        $c->sis->access->actor = $reader;
        self::assertSame([], $this->announcements($c)->page(false)['items']);
    }

    public function testDisabledAccountsDoNotMatchASavedRoleTarget(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $post = $this->draft($c, [$role->value]);
        $c->publish($post, true, 0);
        $reader = $c->sis->user();
        $this->assign($c, $reader, $role);
        $c->sis->access->actor = $reader;
        $c->sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $reader->value]);
        self::assertSame([], $this->announcements($c)->page(false)['items']);
        self::assertSame([], $c->service->news(true));
    }

    public function testPersonalReadAndDeleteRollBackIfMembershipIsLostDuringRequiredAudit(): void
    {
        foreach (['read', 'delete'] as $action) {
            $c = new CampusFixture();
            $role = $this->role($c);
            $post = $this->draft($c, [$role->value]);
            $c->publish($post, true, 0);
            $reader = $c->sis->user();
            $this->assign($c, $reader, $role);
            $c->sis->access->actor = $reader;
            $audit = $this->createMock(AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(
                static function () use ($c, $reader): void {
                    $c->sis->sql->execute('DELETE FROM sis_access_user_roles WHERE user_id = ?', [$reader->value]);
                }
            );
            try {
                $this->announcements($c, $audit)->$action($post, 1);
                self::fail('A personal mutation survived loss of audience membership.');
            } catch (RecordNotFoundException) {
                self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
                self::assertSame([], $c->sis->sql->rows('sis_announcement_dismissals'));
                self::assertCount(1, $c->sis->sql->rows('sis_access_user_roles', ['user_id' => $reader->value]));
            }
        }
    }

    public function testPublicationAuditCannotRemoveATargetRoleOrFailWithoutRollingBack(): void
    {
        foreach ([false, true] as $fail) {
            $c = new CampusFixture();
            $role = $this->role($c);
            $post = $this->draft($c, [$role->value]);
            $r = $c->content->review(
                $post,
                'approve',
                $c->content->get($post)['revision'],
                new PublicationWindow(null, null),
                null
            );
            $audit = $this->createMock(AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(
                static function () use ($c, $role, $fail): void {
                    if ($fail) {
                        throw new \RuntimeException('Fictional mandatory audit failure.');
                    }
                    $c->sis->sql->execute('DELETE FROM sis_access_roles WHERE id = ?', [$role->value]);
                }
            );
            try {
                $this->confirm($this->editor($c, $audit), $post, $r);
                self::fail('An invalid publication completed.');
            } catch (ValidationException | \RuntimeException) {
                self::assertSame('draft', $c->content->get($post)['status']);
                self::assertSame([], $c->sis->sql->rows('sis_content_operations'));
                self::assertCount(1, $c->sis->sql->rows('sis_access_roles', ['id' => $role->value]));
            }
        }
    }

    public static function invalidSelections(): iterable
    {
        $id = '01K00000000000000000000001';
        yield 'empty' => ['roles', 'news', []];
        yield 'duplicate' => ['roles', 'news', [$id, $id]];
        yield 'unknown mode' => ['other', 'news', []];
        yield 'page' => ['roles', 'page', [$id]];
        yield 'link' => ['roles', 'link', [$id]];
        yield 'campus roles' => ['campus', 'news', [$id]];
        yield 'public roles' => ['public', 'news', [$id]];
        yield 'associative' => ['roles', 'news', ['role' => $id]];
        yield 'nested data' => ['roles', 'news', [['id' => $id]]];
        yield 'invalid identity' => ['roles', 'news', ['../private']];
        yield 'too many' => ['roles', 'news', array_map(
            static fn(int $n): string => str_pad((string) $n, 26, '0', STR_PAD_LEFT),
            range(1, 11),
        )];
    }

    #[DataProvider('invalidSelections')]
    public function testSelectionsAreExplicitBoundedNewsOnlyAndTyped(string $mode, string $kind, array $roles): void
    {
        $this->expectException(ValidationException::class);
        new ContentAudience($mode, $kind, $roles);
    }

    public function testRepeatedMigrationPreservesPopulatedTargetsRolesPublicationsAndRecordedFacts(): void
    {
        $c = new CampusFixture();
        $role = $this->role($c);
        $this->draft($c, [$role->value]);
        $tables = ['sis_portal_posts', 'sis_portal_audiences', 'sis_portal_audience_roles',
            'sis_access_roles', 'sis_access_user_roles', 'sis_history_events', 'sis_content_operations'];
        $before = array_map($c->sis->sql->rows(...), $tables);
        $migration = new \CreateSisPortalAudienceRoles('role-audience-schema');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($c->sis->connection, 'test_migrations')]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($c->sis->sql->rows(...), $tables));
    }

    public function testUnknownTargetsCreateNoDraftAndRoleMetadataIsPrivateBoundedAndLiteral(): void
    {
        $c = new CampusFixture();
        $before = $c->sis->sql->rows('sis_portal_posts');
        try {
            $this->draft($c, [EntityId::generate()->value]);
            self::fail('An unknown role created content.');
        } catch (ValidationException) {
            self::assertSame($before, $c->sis->sql->rows('sis_portal_posts'));
        }
        for ($i = 0; $i < 23; $i++) {
            $this->role($c, 'Fictional role ' . $i);
        }
        $literal = $this->role($c, 'Fictional_%literal');
        $page = $c->content->audienceRoles('Fictional role ', null);
        self::assertCount(20, $page['items']);
        self::assertCount(3, $c->content->audienceRoles('Fictional role ', new EntityId($page['next_after']))['items']);
        self::assertSame(
            [['id' => $literal->value, 'name' => 'Fictional_%literal']],
            $c->content->audienceRoles('_%', null)['items']
        );
        self::assertSame(['id', 'name'], array_keys($page['items'][0]));
        $c->sis->access->staff = false;
        $c->sis->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $c->content->audienceRoles('', null);
    }
}
