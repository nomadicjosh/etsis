<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Service\CampusAnnouncementService;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAnnouncementRepository;
use Infrastructure\Persistence\Repository\SqlPortalRepository;
use Infrastructure\Portal\SymfonyContentMarkup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CampusAnnouncementsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function service(
        CampusFixture $c,
        ?AuditTrail $audit = null,
        ?StudentAccess $access = null,
    ): CampusAnnouncementService {
        $s = $c->sis;
        return new CampusAnnouncementService(
            new SqlAnnouncementRepository($s->sql, $s->clock),
            new SqlPortalRepository($s->sql, $s->clock),
            $access ?? $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new SymfonyContentMarkup(),
        );
    }

    private function draft(CampusFixture $c, string $audience = 'campus', string $format = 'plain'): EntityId
    {
        return $c->content->save(
            null,
            'news',
            'fixture-' . strtolower(EntityId::generate()->value),
            'Fictional campus announcement',
            $format === 'plain' ? 'Fictional <b>literal</b> announcement.'
            : '<p>Fictional <strong>formatted</strong> announcement.</p>',
            $audience,
            null,
            $format
        );
    }

    public function testOnlyCurrentlyPublishedExplicitCampusNewsAppearsAndOpeningDoesNotMarkRead(): void
    {
        $c = new CampusFixture();
        $published = $this->draft($c);
        $public = $this->draft($c, 'public');
        $draft = $this->draft($c);
        $c->publish($published, true, 0);
        $c->publish($public, true, 0);
        $service = $this->service($c);
        self::assertSame([$published->value], array_column($service->page()['items'], 'id'));
        self::assertStringNotContainsString('content', json_encode($service->page(), JSON_THROW_ON_ERROR));
        self::assertSame('Fictional <b>literal</b> announcement.', $service->detail($published)['content']);
        self::assertTrue($service->detail($published)['unread']);
        self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
        foreach ([$public, $draft] as $hidden) {
            try {
                $service->detail($hidden);
                self::fail('Non-campus or draft content escaped its scope.');
            } catch (RecordNotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReadsBelongOnlyToTheSignedInAccountAndRetriesPreserveTheOriginalTimestamp(): void
    {
        $c = new CampusFixture();
        $id = $this->draft($c);
        $c->publish($id, true, 0);
        $service = $this->service($c);
        $reader = $c->sis->user();
        $c->sis->access->actor = $reader;
        $c->sis->access->staff = false;
        self::assertFalse($service->read($id, 1)['unread']);
        $rows = $c->sis->sql->rows('sis_announcement_reads');
        $audit = $c->sis->sql->rows('sis_audit');
        $c->sis->clock->time = $c->sis->clock->time->modify('+1 hour');
        self::assertFalse($service->read($id, 1)['unread']);
        self::assertSame($rows, $c->sis->sql->rows('sis_announcement_reads'));
        self::assertSame($audit, $c->sis->sql->rows('sis_audit'));
        self::assertSame([], $service->page()['items']);
        self::assertFalse($service->page(false)['items'][0]['unread']);
        $c->sis->access->actor = $c->sis->user();
        self::assertTrue($service->page()['items'][0]['unread']);
        self::assertSame($reader->value, $rows[0]['owner_user_id']);
        self::assertStringNotContainsString('Fictional', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertSame([], $c->sis->sql->rows('sis_history_events'));
    }

    public function testRepublishingRequiresReadingTheNewRevisionAndCannotBeHiddenWithAnOldRequest(): void
    {
        $c = new CampusFixture();
        $id = $this->draft($c);
        $c->publish($id, true, 0);
        $service = $this->service($c);
        $service->read($id, 1);
        $previous = $c->sis->sql->rows('sis_announcement_reads');
        $c->content->save(
            $id,
            'news',
            $c->content->get($id)['code'],
            'Revised announcement',
            'A revised fictional announcement.',
            'campus',
            1
        );
        self::assertSame([], $service->page(false)['items']);
        $c->publish($id, true, 2);
        self::assertTrue($service->detail($id)['unread']);
        try {
            $service->read($id, 1);
            self::fail('An earlier read request hid a later publication.');
        } catch (ConcurrentModificationException) {
            self::assertSame($previous, $c->sis->sql->rows('sis_announcement_reads'));
        }
        self::assertFalse($service->read($id, 3)['unread']);
        self::assertSame([], $service->page()['items']);
    }

    public function testMissingAudienceAndArchivedOrExpiredAnnouncementsCannotBeReadOrMarked(): void
    {
        $c = new CampusFixture();
        $id = $this->draft($c);
        $c->publish($id, true, 0);
        $service = $this->service($c);
        $c->sis->sql->execute('DELETE FROM sis_portal_audiences WHERE post_id = ?', [$id->value]);
        self::assertSame([], $service->page()['items']);
        try {
            $service->detail($id);
            self::fail('An unknown audience was inferred.');
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $c->repository->setAudience($id, 'campus');
        $c->sis->sql->update('sis_portal_posts', ['visible_from' => '2026-08-10T18:00:00Z',
            'visible_until' => '2026-08-10T19:00:00Z'], ['id' => $id->value]);
        self::assertSame([], $service->page()['items']);
        $c->sis->clock->time = new \DateTimeImmutable('2026-08-10T18:00:00Z');
        self::assertCount(1, $service->page()['items']);
        $service->read($id, 1);
        $saved = $c->sis->sql->rows('sis_announcement_reads');
        $c->sis->clock->time = new \DateTimeImmutable('2026-08-10T19:00:00Z');
        self::assertSame([], $service->page(false)['items']);
        try {
            $service->read($id, 1);
            self::fail('Expired content was marked read.');
        } catch (RecordNotFoundException) {
            self::assertSame($saved, $c->sis->sql->rows('sis_announcement_reads'));
        }
        $c->sis->sql->update('sis_portal_posts', ['visible_from' => null, 'visible_until' => null,
            'status' => 'archived'], ['id' => $id->value]);
        self::assertSame([], $service->page(false)['items']);
        $this->expectException(RecordNotFoundException::class);
        $service->detail($id);
    }

    public function testPagesAreBoundedAndReadStateDoesNotInflateOrDuplicateRows(): void
    {
        $c = new CampusFixture();
        for ($n = 0; $n < 25; $n++) {
            $id = $this->draft($c);
            $c->publish($id, true, 0);
        }
        $service = $this->service($c);
        $first = $service->page();
        self::assertCount(20, $first['items']);
        $second = $service->page(true, new EntityId($first['next_after']));
        self::assertCount(5, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(25, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        $service->read(new EntityId($first['items'][0]['id']), 1);
        self::assertCount(20, $service->page(false)['items']);
        self::assertCount(20, $service->page()['items']);
    }

    public function testRichTextBecomesLiteralSafeTextAndNoContentIsStoredInReadState(): void
    {
        $c = new CampusFixture();
        $id = $this->draft($c, format: 'html');
        $c->publish($id, true, 0);
        $service = $this->service($c);
        $detail = $service->detail($id);
        self::assertStringContainsString('formatted', $detail['content']);
        self::assertStringNotContainsString('<strong>', $detail['content']);
        $service->read($id, 1);
        self::assertStringNotContainsString('formatted', json_encode(
            $c->sis->sql->rows('sis_announcement_reads'),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testFinalAuditFailuresSourceChangesExpiryAndLostAuthorityRollBackReadState(): void
    {
        foreach (['audit', 'owner', 'source', 'audience', 'expiry'] as $failure) {
            $c = new CampusFixture();
            $id = $this->draft($c);
            $c->publish($id, true, 0);
            $callback = static function () use ($c, $id, $failure): void {
                match ($failure) {
                    'audit' => throw new RuntimeException('Fictional mandatory audit failure.'),
                    'owner' => $c->sis->access->actor = $c->sis->user(),
                    'source' => $c->sis->sql->update('sis_portal_posts', ['version' => 2], ['id' => $id->value]),
                    'audience' => $c->repository->setAudience($id, 'public'),
                    'expiry' => $c->sis->sql->update(
                        'sis_portal_posts',
                        ['visible_until' => '2026-08-10T17:00:00Z'],
                        ['id' => $id->value]
                    ),
                };
            };
            $audit = new class ($callback) implements AuditTrail {
                public function __construct(private \Closure $callback)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    ($this->callback)();
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            try {
                $this->service($c, $audit)->read($id, 1);
                self::fail('A final validation failure committed read state.');
            } catch (
                RuntimeException | AccessDeniedException | ConcurrentModificationException | RecordNotFoundException
            ) {
                self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'), $failure);
                self::assertSame(1, (int) $c->sis->sql->one('sis_portal_posts', ['id' => $id->value])['version']);
            }
        }
    }

    public function testUnauthenticatedReadersCannotLoadCampusAnnouncements(): void
    {
        $c = new CampusFixture();
        $access = $this->createStub(StudentAccess::class);
        $access->method('authenticatedUserId')->willThrowException(new AccessDeniedException());
        $this->expectException(AccessDeniedException::class);
        $this->service($c, access: $access)->page();
    }
    public function testDeletingANotificationErasesReadMetadataAndSuppressesOnlyThisAccountsCurrentVersion(): void
    {
        $c = new CampusFixture();
        $id = $this->draft($c);
        $c->publish($id, true, 0);
        $service = $this->service($c);
        $service->read($id, 1);
        $posts = $c->sis->sql->rows('sis_portal_posts');
        self::assertSame(['deleted' => true], $service->delete($id, 1));
        self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
        self::assertSame([], $service->page(false)['items']);
        self::assertCount(1, $c->sis->sql->rows('sis_announcement_dismissals'));
        self::assertSame($posts, $c->sis->sql->rows('sis_portal_posts'));
        self::assertSame(['deleted' => true], $service->delete($id, 1));
        try {
            $service->read($id, 1);
            self::fail('A deleted announcement recreated its read metadata.');
        } catch (RecordNotFoundException) {
            self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
        }
        $owner = $c->sis->access->actor;
        $c->sis->access->actor = $c->sis->user();
        self::assertSame($id->value, $service->page()['items'][0]['id']);
        $c->sis->access->actor = $owner;
        $c->content->save(
            $id,
            'news',
            $c->content->get($id)['code'],
            'Revised announcement',
            'A revised fictional announcement.',
            'campus',
            1
        );
        $c->publish($id, true, 2);
        self::assertSame($id->value, $service->page()['items'][0]['id']);
        self::assertTrue($service->page()['items'][0]['unread']);
        $service->delete($id, 1); // An old completed deletion cannot suppress the new publication.
        self::assertCount(1, $service->page()['items']);
        self::assertCount(1, $c->sis->sql->rows('sis_announcement_dismissals'));
    }

    public function testFailedDeletionRollsBackSuppressionAndReadStateTogether(): void
    {
        foreach (['audit', 'owner', 'source'] as $failure) {
            $c = new CampusFixture();
            $id = $this->draft($c);
            $c->publish($id, true, 0);
            $this->service($c)->read($id, 1);
            $before = $c->sis->sql->rows('sis_announcement_reads');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($c, $id, $failure): void {
                match ($failure) {
                    'audit' => throw new RuntimeException('Fictional audit failure.'),
                    'owner' => $c->sis->access->actor = $c->sis->user(),
                    'source' => $c->sis->sql->update('sis_portal_posts', ['version' => 2], ['id' => $id->value]),
                };
            });
            try {
                $this->service($c, $audit)->delete($id, 1);
                self::fail('A partial notification deletion committed.');
            } catch (RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                self::assertSame($before, $c->sis->sql->rows('sis_announcement_reads'));
                self::assertSame([], $c->sis->sql->rows('sis_announcement_dismissals'));
            }
        }
    }
}
