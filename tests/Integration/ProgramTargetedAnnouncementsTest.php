<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Service\CampusAnnouncementService;
use Domain\Portal\Service\CampusContentService;
use Domain\Portal\ValueObject\ContentAudience;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAnnouncementRepository;
use Infrastructure\Persistence\Repository\SqlContentAudienceRepository;
use Infrastructure\Persistence\Repository\SqlPortalRepository;
use Infrastructure\Portal\SymfonyContentMarkup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProgramTargetedAnnouncementsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function program(CampusFixture $c, string $title = 'Fictional program'): EntityId
    {
        $id = EntityId::generate();
        $original = $c->sis->sql->one('sis_programs', ['id' => $c->sis->program->value]);
        $c->sis->sql->insert('sis_programs', array_replace($original, [
            'id' => $id->value, 'code' => 'P-' . $id->value, 'title' => $title,
        ]));
        return $id;
    }

    private function pursuit(CampusFixture $c, EntityId $student, EntityId $program): EntityId
    {
        $id = EntityId::generate();
        $c->sis->sql->insert('sis_student_programs', ['id' => $id->value, 'student_id' => $student->value,
            'program_id' => $program->value, 'entry_term_id' => $c->sis->term->value, 'status' => 'active']);
        return $id;
    }

    private function draft(CampusFixture $c, array $programs): EntityId
    {
        return $c->content->save(
            null,
            'news',
            'fictional-' . strtolower(EntityId::generate()->value),
            'Fictional program notice',
            'Private fictional announcement.',
            'programs',
            null,
            audiencePrograms: $programs
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

    private function review(CampusFixture $c, EntityId $post): array
    {
        return $c->content->review(
            $post,
            'approve',
            $c->content->get($post)['revision'],
            new PublicationWindow(null, null),
            null
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

    public function testSecondaryActivePursuitsMatchEveryPrivateReaderWithoutPublicOrSuperBypasses(): void
    {
        $c = new CampusFixture();
        $program = $this->program($c);
        $reader = $c->sis->user();
        $student = $c->sis->student($reader);
        $this->pursuit($c, $student->id, $program);
        $otherProgram = $this->program($c, 'Fictional other program');
        $post = $this->draft($c, [$otherProgram->value, $program->value]);
        $c->publish($post, true, 0);
        $code = $c->content->get($post)['code'];
        $unmatched = $c->sis->user();
        $super = $c->sis->sql->one('sis_access_roles', ['name' => 'super']);
        $c->sis->sql->insert('sis_access_user_roles', ['id' => EntityId::generate()->value,
            'user_id' => $unmatched->value, 'role_id' => $super['id']]);
        $before = \EventStoreFixture::historyDigest($c->sis->sql);
        $c->sis->access->actor = $reader;
        $c->sis->access->staff = false;
        $service = $this->announcements($c);
        self::assertSame([$post->value], array_column($service->page(false)['items'], 'id'));
        self::assertArrayNotHasKey('audience_programs', $service->detail($post));
        self::assertSame([$post->value], array_column($c->service->news(true), 'id'));
        self::assertSame($post->value, $c->service->article($code, true)['id']);
        self::assertArrayNotHasKey('audience_programs', $c->service->article($code, true));
        self::assertSame($post->value, $c->posts->published($code)->id->value);
        self::assertSame([], $c->service->news());
        $c->sis->access->actor = $unmatched;
        $c->sis->access->staff = true;
        self::assertSame([], $service->page(false)['items']);
        foreach (
            [fn() => $service->detail($post), fn() => $service->read($post, 1),
            fn() => $service->delete($post, 1), fn() => $c->service->article($code),
            fn() => $c->service->article($code, true), fn() => $c->posts->published($code)] as $read
        ) {
            try {
                $read();
                self::fail('An unmatched or public account received program-targeted content.');
            } catch (RecordNotFoundException) {
                self::assertTrue(true);
            }
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($c->sis->sql));
    }

    public function testCurrentEligibilityRequiresActualPursuitStudentAndCanonicalEnabledAccount(): void
    {
        foreach (
            ['closed', 'inactive_student', 'disabled', 'different_owner', 'primary_only', 'applicant_only'] as $kind
        ) {
            $c = new CampusFixture();
            $program = $this->program($c);
            $post = $this->draft($c, [$program->value]);
            $c->publish($post, true, 0);
            $reader = $c->sis->user();
            if ($kind !== 'applicant_only') {
                $student = $c->sis->student($reader);
                $pursuit = $this->pursuit($c, $student->id, $program);
                if ($kind === 'closed') {
                    $c->sis->sql->update('sis_student_programs', ['status' => 'closed'], ['id' => $pursuit->value]);
                }
                if ($kind === 'primary_only') {
                    $c->sis->sql->update(
                        'sis_students',
                        ['primary_program_id' => $program->value],
                        ['id' => $student->id->value]
                    );
                    $c->sis->sql->execute('DELETE FROM sis_student_programs WHERE id = ?', [$pursuit->value]);
                }
                if ($kind === 'inactive_student') {
                    $c->sis->sql->update('sis_students', ['active' => 0], ['id' => $student->id->value]);
                }
                if ($kind === 'different_owner') {
                    $c->sis->sql->update(
                        'sis_persons',
                        ['owner_user_id' => $c->sis->user()->value],
                        ['id' => $student->personId->value]
                    );
                }
                if ($kind === 'disabled') {
                    $c->sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $reader->value]);
                }
            } else {
                $person = $c->sis->legacyPerson($reader, 'Fictional', 'Applicant');
                $c->sis->legacyApplication($person->id, $program, $c->sis->term);
            }
            $c->sis->access->actor = $reader;
            self::assertSame([], $this->announcements($c)->page(false)['items'], $kind);
            self::assertSame([], $c->service->news(true), $kind);
        }
    }

    public function testAnActivePrimaryPursuitMatchesWithoutAnyAudienceRoleGrant(): void
    {
        $c = new CampusFixture();
        $reader = $c->sis->user();
        $c->sis->student($reader);
        $post = $this->draft($c, [$c->sis->program->value]);
        $c->publish($post, true, 0);
        $c->sis->access->actor = $reader;
        self::assertSame([$post->value], array_column($this->announcements($c)->page(false)['items'], 'id'));
    }

    public function testPursuitClosureRevokesOpenDetailsMutationsAndCursorsWithoutRecreatingReadState(): void
    {
        $c = new CampusFixture();
        $reader = $c->sis->user();
        $student = $c->sis->student($reader);
        $program = $this->program($c);
        $pursuit = $this->pursuit($c, $student->id, $program);
        $post = $this->draft($c, [$program->value]);
        $c->publish($post, true, 0);
        $c->sis->access->actor = $reader;
        $service = $this->announcements($c);
        $service->read($post, 1);
        $before = $c->sis->sql->rows('sis_announcement_reads');
        $c->sis->sql->update('sis_student_programs', ['status' => 'closed'], ['id' => $pursuit->value]);
        self::assertSame([], $service->page(false)['items']);
        try {
            $service->page(false, $post);
            self::fail('A now-ineligible cursor was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        foreach (
            [fn() => $service->detail($post), fn() => $service->read($post, 1),
                fn() => $service->delete($post, 1)] as $call
        ) {
            try {
                $call();
                self::fail('Earlier read state granted current audience membership.');
            } catch (RecordNotFoundException) {
                self::assertSame($before, $c->sis->sql->rows('sis_announcement_reads'));
            }
        }
        $c->sis->sql->update('sis_student_programs', ['status' => 'active'], ['id' => $pursuit->value]);
        self::assertFalse($service->detail($post)['unread']);
        $service->delete($post, 1);
        self::assertSame([], $service->page(false)['items']);
        self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
    }

    public function testProgramFilteringPrecedesPagingWithoutDuplicatesAcrossMatchingPursuits(): void
    {
        $c = new CampusFixture();
        $reader = $c->sis->user();
        $student = $c->sis->student($reader);
        $second = $this->program($c);
        $this->pursuit($c, $student->id, $second);
        $excluded = $this->program($c, 'Fictional unmatched');
        $visible = [];
        for ($i = 0; $i < 22; $i++) {
            foreach ([[$c->sis->program->value, $second->value], [$excluded->value]] as $targets) {
                $post = $this->draft($c, $targets);
                $c->publish($post, true, 0);
                if (count($targets) === 2) {
                    $visible[] = $post->value;
                }
            }
        }
        $c->sis->access->actor = $reader;
        $service = $this->announcements($c);
        $first = $service->page(false);
        self::assertCount(20, $first['items']);
        $secondPage = $service->page(false, new EntityId($first['next_after']));
        self::assertCount(2, $secondPage['items']);
        $actual = [...array_column($first['items'], 'id'), ...array_column($secondPage['items'], 'id')];
        sort($visible);
        sort($actual);
        self::assertSame($visible, $actual);
    }

    public function testTargetChangesInvalidateReviewsAndAcceptedRetriesPreserveLaterDrafts(): void
    {
        $c = new CampusFixture();
        $first = $this->program($c);
        $second = $this->program($c, 'Fictional second program');
        $post = $this->draft($c, [$first->value]);
        $review = $this->review($c, $post);
        $targets = new SqlContentAudienceRepository($c->sis->sql);
        $targets->replacePrograms($post, $targets->programDefinitions([$second->value]));
        try {
            $this->confirm($c->content, $post, $review);
            self::fail('An unreviewed target substitution was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('draft', $c->content->get($post)['status']);
        }
        $review = $this->review($c, $post);
        $receipt = $this->confirm($c->content, $post, $review);
        $saved = $c->content->get($post);
        $c->content->save($post, 'news', $saved['code'], 'Later fictional title', 'Later wording.', 'campus', 1);
        self::assertSame($receipt, $this->confirm($c->content, $post, $review));
        self::assertSame([], $targets->selectedPrograms($post));
        self::assertArrayNotHasKey('audience_programs', $c->content->get($post));
        self::assertSame('draft', $c->content->get($post)['status']);
    }

    public function testRemovedAndRenamedDefinitionsRequireFreshAuthorReview(): void
    {
        $c = new CampusFixture();
        $program = $this->program($c);
        $post = $this->draft($c, [$program->value]);
        $original = $c->content->get($post)['audience_programs'];
        $c->sis->sql->update('sis_programs', ['title' => 'Changed fictional title'], ['id' => $program->value]);
        try {
            $this->review($c, $post);
            self::fail('A changed label bypassed a fresh saved review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($original, $c->content->get($post)['audience_programs']);
        }
        $c->sis->sql->execute('DELETE FROM sis_programs WHERE id = ?', [$program->value]);
        $replacement = $this->program($c);
        self::assertNotSame($program->value, $replacement->value);
        self::assertSame($original, $c->content->get($post)['audience_programs']);
        try {
            $this->review($c, $post);
            self::fail('A replacement label adopted a missing program identity.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function testPublishedTargetsRetainRemovedIdentityAndNeverAdoptTheSameCodeReplacement(): void
    {
        $c = new CampusFixture();
        $program = $this->program($c);
        $definition = $c->sis->sql->one('sis_programs', ['id' => $program->value]);
        $post = $this->draft($c, [$program->value]);
        $c->publish($post, true, 0);
        $targets = $c->content->get($post)['audience_programs'];
        $c->sis->sql->execute('DELETE FROM sis_programs WHERE id = ?', [$program->value]);
        $replacement = EntityId::generate();
        $c->sis->sql->insert('sis_programs', array_replace($definition, ['id' => $replacement->value]));
        self::assertSame($targets, $c->content->get($post)['audience_programs']);
        $reader = $c->sis->user();
        $student = $c->sis->student($reader);
        $this->pursuit($c, $student->id, $replacement);
        $c->sis->access->actor = $reader;
        self::assertSame([], $this->announcements($c)->page(false)['items']);
    }

    public function testDraftTargetReplacementRollsBackOnRequiredAuditFailureOrActorChange(): void
    {
        foreach (['audit', 'actor'] as $failure) {
            $c = new CampusFixture();
            $program = $this->program($c);
            $post = $this->draft($c, [$program->value]);
            $next = $this->program($c, 'Later fictional program');
            $actor = $c->sis->user();
            $saved = $c->content->get($post);
            $tables = ['sis_portal_posts', 'sis_portal_audiences',
                'sis_portal_audience_programs', 'sis_history_events'];
            $before = array_map($c->sis->sql->rows(...), $tables);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($c, $actor, $failure): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional required draft audit failure.');
                }
                $c->sis->access->actor = $actor;
            });
            try {
                $this->editor($c, $audit)->save(
                    $post,
                    'news',
                    $saved['code'],
                    'Changed draft',
                    'Changed text.',
                    'programs',
                    0,
                    audiencePrograms: [$next->value]
                );
                self::fail('A partial target or draft change survived a required failure.');
            } catch (\RuntimeException) {
                self::assertSame($before, array_map($c->sis->sql->rows(...), $tables));
            }
        }
    }

    public function testPersonalReadAndDeleteRollbackWhenPursuitEligibilityChangesDuringRequiredAudit(): void
    {
        foreach (['read', 'delete'] as $action) {
            $c = new CampusFixture();
            $reader = $c->sis->user();
            $student = $c->sis->student($reader);
            $post = $this->draft($c, [$c->sis->program->value]);
            $c->publish($post, true, 0);
            $c->sis->access->actor = $reader;
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($c, $student): void {
                $c->sis->sql->update(
                    'sis_student_programs',
                    ['status' => 'closed'],
                    ['student_id' => $student->id->value]
                );
            });
            try {
                $this->announcements($c, $audit)->$action($post, 1);
                self::fail('A personal mutation survived loss of current eligibility.');
            } catch (RecordNotFoundException) {
                self::assertSame([], $c->sis->sql->rows('sis_announcement_reads'));
                self::assertSame([], $c->sis->sql->rows('sis_announcement_dismissals'));
                self::assertSame('active', $c->sis->sql->one(
                    'sis_student_programs',
                    ['student_id' => $student->id->value]
                )['status']);
            }
        }
    }

    public function testPublicationAndDraftAuditFailuresRollBackAllTargetAndPublicationChanges(): void
    {
        foreach (['failure', 'rename', 'targets', 'authority'] as $kind) {
            $c = new CampusFixture();
            $program = $this->program($c);
            $post = $this->draft($c, [$program->value]);
            $review = $this->review($c, $post);
            $before = $c->sis->sql->rows('sis_portal_audience_programs');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($c, $program, $post, $kind): void {
                if ($kind === 'failure') {
                    throw new \RuntimeException('Fictional mandatory audit failure.');
                }
                if ($kind === 'rename') {
                    $c->sis->sql->update('sis_programs', ['title' => 'Changed'], ['id' => $program->value]);
                }
                if ($kind === 'targets') {
                    new SqlContentAudienceRepository($c->sis->sql)->replacePrograms($post, []);
                }
                if ($kind === 'authority') {
                    $c->sis->access->staff = false;
                    $c->sis->access->grants = [];
                }
            });
            try {
                $this->confirm($this->editor($c, $audit), $post, $review);
                self::fail('A publication survived required audit or final source/authority failure.');
            } catch (\RuntimeException | ValidationException) {
                $c->sis->access->staff = true;
                self::assertSame('draft', $c->content->get($post)['status']);
                self::assertSame([], $c->sis->sql->rows('sis_content_operations'));
                self::assertSame($before, $c->sis->sql->rows('sis_portal_audience_programs'));
            }
        }
    }

    public function testMetadataIsBoundedLiteralLabelOnlyAndRequiresCurrentAuthorAuthority(): void
    {
        $c = new CampusFixture();
        for ($i = 0; $i < 23; $i++) {
            $this->program($c, 'Fictional program ' . $i);
        }
        $literal = $this->program($c, 'Fictional_%literal');
        $page = $c->content->audiencePrograms('Fictional program ');
        self::assertCount(20, $page['items']);
        self::assertCount(3, $c->content->audiencePrograms(
            'Fictional program ',
            new EntityId($page['next_after'])
        )['items']);
        self::assertSame(['id', 'name'], array_keys($page['items'][0]));
        self::assertSame([$literal->value], array_column($c->content->audiencePrograms('_%')['items'], 'id'));
        $before = $c->sis->sql->rows('sis_portal_posts');
        try {
            $this->draft($c, [EntityId::generate()->value]);
            self::fail('Unknown target created a draft.');
        } catch (ValidationException) {
            self::assertSame($before, $c->sis->sql->rows('sis_portal_posts'));
        }
        $c->sis->access->staff = false;
        $c->sis->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $c->content->audiencePrograms('');
    }

    public function testMigrationCanRepeatOverPopulatedTargetsWithoutChangingFacts(): void
    {
        $c = new CampusFixture();
        $this->draft($c, [$c->sis->program->value]);
        $tables = ['sis_portal_posts', 'sis_portal_audiences', 'sis_portal_audience_programs',
            'sis_programs', 'sis_student_programs', 'sis_history_events', 'sis_content_operations'];
        $before = array_map($c->sis->sql->rows(...), $tables);
        $migration = new \CreateSisPortalAudiencePrograms('program-audience-schema');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($c->sis->connection, 'test_migrations')]));
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($c->sis->sql->rows(...), $tables));
    }

    public static function invalidSelections(): iterable
    {
        $id = '01K00000000000000000000001';
        yield 'empty' => ['programs', 'news', [], []];
        yield 'duplicate' => ['programs', 'news', [], [$id, $id]];
        yield 'page' => ['programs', 'page', [], [$id]];
        yield 'link' => ['programs', 'link', [], [$id]];
        yield 'campus' => ['campus', 'news', [], [$id]];
        yield 'public' => ['public', 'news', [], [$id]];
        yield 'roles' => ['roles', 'news', [$id], [$id]];
        yield 'associative' => ['programs', 'news', [], ['program' => $id]];
        yield 'nested' => ['programs', 'news', [], [['id' => $id]]];
        yield 'invalid identity' => ['programs', 'news', [], ['../private']];
        yield 'too many' => ['programs', 'news', [], array_map(
            static fn(int $n): string => str_pad((string) $n, 26, '0', STR_PAD_LEFT),
            range(1, 11)
        )];
    }

    #[DataProvider('invalidSelections')]
    public function testProgramSelectionsAreExplicitTypedBoundedAndNewsOnly(
        string $mode,
        string $kind,
        array $roles,
        array $programs,
    ): void {
        $this->expectException(ValidationException::class);
        new ContentAudience($mode, $kind, $roles, $programs);
    }
}
