<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Communications\Enum\TemplateLibraryScope;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Infrastructure\Persistence\Repository\SqlCampaignRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class CampaignTemplatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(
        SisFixture $f,
        ?AuditTrail $audit = null,
        ?CampaignRepository $repository = null,
        ?MessageCipher $cipher = null
    ): CampaignService {
        $transport = $this->createMock(MailTransport::class);
        $transport->expects(self::never())->method('send');
        return new CampaignService(
            $repository ?? new SqlCampaignRepository($f->sql),
            $cipher ?? $f->dropCipher,
            $transport,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit
        );
    }

    public function testTemplateEditsRetirementAndRestorationLeaveIndependentDraftsAndHistoryUntouched(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $events = $f->sql->rows('sis_history_events');
        $template = $service->createTemplate('Fictional reusable invitation', 'Fictional reusable wording.');
        self::assertSame('template', $template->status);
        self::assertFalse($service->show($template->id)['can_dispatch']);
        self::assertStringNotContainsString('Fictional', $template->encryptedContent);
        $draft = $service->draftFromTemplate($template->id, 0);
        self::assertNotSame($template->id->value, $draft->id->value);
        self::assertSame('draft', $draft->status);
        self::assertSame(0, $draft->version);
        $service->edit($template->id, 'Future wording', 'Fictional future version.', 0);
        self::assertSame('Fictional reusable wording.', $service->show($draft->id)['body']);
        self::assertSame('template', $service->show($template->id)['status']);
        $service->retireTemplate($template->id, 1, true);
        self::assertSame([], $service->templates()['items']);
        self::assertSame($template->id->value, $service->templates(TemplateLibraryScope::Retired)['items'][0]['id']);
        foreach (['copy', 'edit'] as $operation) {
            try {
                if ($operation === 'copy') {
                    $service->draftFromTemplate($template->id, 2);
                } else {
                    $service->edit($template->id, 'Rejected change', 'Rejected text.', 2);
                }
                self::fail('A retired template was used or edited.');
            } catch (DomainException) {
                self::assertSame('template_retired', $service->show($template->id)['status']);
            }
        }
        $service->retireTemplate($template->id, 2, false);
        $freshDraft = $service->draftFromTemplate($template->id, 3);
        self::assertSame('Future wording', $service->show($freshDraft->id)['subject']);
        self::assertSame([], $f->sql->rows('sis_deliveries'));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $audit = json_encode($f->sql->rows('sis_audit'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Fictional', $audit);
    }

    public function testTemplatesCannotQueueOrBecomeOrdinaryCampaignsThroughStatusTransitions(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Fictional template', 'Fictional body.');
        try {
            $service->queue($template->id, [$f->access->actor], 0);
            self::fail('A template entered the delivery queue.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_deliveries'));
        }
        $this->expectException(DomainException::class);
        $template->transition('queued');
    }

    public function testStaleTemplateVersionsCannotEditCopyOrRetireANewerRevision(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Fictional template', 'Fictional body.');
        $service->edit($template->id, 'Current template', 'Current text.', 0);
        foreach (['edit', 'copy', 'retire'] as $operation) {
            try {
                match ($operation) {
                    'edit' => $service->edit($template->id, 'Stale', 'Stale text.', 0),
                    'copy' => $service->draftFromTemplate($template->id, 0),
                    'retire' => $service->retireTemplate($template->id, 0, true),
                };
                self::fail('A stale template operation was accepted.');
            } catch (ConcurrentModificationException) {
                self::assertSame('Current template', $service->show($template->id)['subject']);
                self::assertCount(1, $f->sql->rows('sis_campaigns'));
            }
        }
    }

    public function testLibraryIsOwnerScopedAndAdministrativeCopiesBelongToTheCurrentActor(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Private fictional template', 'Private fictional body.');
        $originalOwner = $f->access->actor;
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['sis:communications:manage'];
        self::assertSame([], $service->templates()['items']);
        foreach (['read', 'edit', 'copy', 'retire'] as $operation) {
            try {
                match ($operation) {
                    'read' => $service->show($template->id),
                    'edit' => $service->edit($template->id, 'Denied', 'Denied text.', 0),
                    'copy' => $service->draftFromTemplate($template->id, 0),
                    'retire' => $service->retireTemplate($template->id, 0, true),
                };
                self::fail('Another account accessed a private template.');
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_campaigns'));
            }
        }
        $f->access->grants[] = 'sis:communications:admin';
        $draft = $service->draftFromTemplate($template->id, 0);
        self::assertSame($f->access->actor->value, $draft->ownerUserId->value);
        $stored = new SqlCampaignRepository($f->sql)->get($template->id);
        self::assertSame($originalOwner->value, $stored->ownerUserId->value);
        self::assertSame([], $service->templates()['items']);
    }

    public function testLibraryPagesAreBoundedAndOmitBodiesAndOtherMessageStates(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        for ($i = 0; $i < 23; ++$i) {
            $service->createTemplate('Fictional template ' . $i, 'Private body not shown in the list.');
        }
        $service->create('Ordinary draft', 'Not a reusable template.');
        $retired = $service->createTemplate('Retired template', 'Retained wording.');
        $service->retireTemplate($retired->id, 0, true);
        $opens = 0;
        $cipher = $this->createStub(MessageCipher::class);
        $cipher->method('open')->willReturnCallback(static function (string $value) use ($f, &$opens): string {
            ++$opens;
            return $f->dropCipher->open($value);
        });
        $first = $this->service($f, cipher: $cipher)->templates();
        self::assertCount(20, $first['items']);
        self::assertSame(20, $opens);
        self::assertSame(['id', 'subject', 'version'], array_keys($first['items'][0]));
        self::assertStringNotContainsString('Private body', json_encode($first, JSON_THROW_ON_ERROR));
        $second = $service->templates(after: new EntityId($first['next_after']));
        self::assertCount(3, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(23, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        self::assertCount(1, $service->templates(TemplateLibraryScope::Retired)['items']);
    }

    public function testRequiredAuditFailureRollsBackEveryTemplateMutation(): void
    {
        foreach (['create', 'edit', 'copy', 'retire'] as $operation) {
            $f = new SisFixture();
            $real = $this->service($f);
            $template = $operation === 'create' ? null : $real->createTemplate('Fictional template', 'Fictional body.');
            $before = $f->sql->rows('sis_campaigns');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willThrowException(new RuntimeException('Fictional required audit failure.'));
            $service = $this->service($f, audit: $audit);
            try {
                match ($operation) {
                    'create' => $service->createTemplate('Fictional new template', 'Fictional new body.'),
                    'edit' => $service->edit($template->id, 'Changed', 'Changed body.', 0),
                    'copy' => $service->draftFromTemplate($template->id, 0),
                    'retire' => $service->retireTemplate($template->id, 0, true),
                };
                self::fail('A template operation bypassed its required audit.');
            } catch (RuntimeException $e) {
                self::assertSame('Fictional required audit failure.', $e->getMessage());
                self::assertSame($before, $f->sql->rows('sis_campaigns'));
            }
        }
    }

    public function testTemplateCopyRollsBackWhenItsActorChangesDuringAudit(): void
    {
        $f = new SisFixture();
        $template = $this->service($f)->createTemplate('Fictional template', 'Fictional body.');
        $other = $f->user();
        $before = $f->sql->rows('sis_campaigns');
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f, $other): void {
            $f->access->actor = $other;
        });
        try {
            $this->service($f, audit: $audit)->draftFromTemplate($template->id, 0);
            self::fail('An actor change was accepted during template copy.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows('sis_campaigns'));
        }
    }

    public function testTemplateLibraryRechecksAccessAfterItsRead(): void
    {
        $f = new SisFixture();
        $template = $this->service($f)->createTemplate('Fictional template', 'Fictional body.');
        $repository = $this->createStub(CampaignRepository::class);
        $repository->method('templates')->willReturnCallback(static function () use ($f, $template): array {
            $f->access->staff = false;
            return [$template];
        });
        $this->expectException(AccessDeniedException::class);
        $this->service($f, repository: $repository)->templates();
    }

    public function testInstitutionalApprovalSharesOnlyReviewedWordingAndCopiesRemainIndependent(): void
    {
        $f = new SisFixture();
        $author = $f->access->actor;
        $f->access->staff = false;
        $f->access->grants = ['sis:communications:manage'];
        $service = $this->service($f);
        $events = $f->sql->rows('sis_history_events');
        $template = $service->createTemplate('Fictional shared wording', 'Reviewed fictional body.');
        $service->reviewTemplate($template->id, 0, 'submit');
        self::assertSame([], $service->templates()['items']);
        self::assertCount(1, $service->templates(TemplateLibraryScope::Submitted)['items']);
        self::assertFalse($service->show($template->id)['can_dispatch']);
        foreach (['review-list', 'approve'] as $operation) {
            try {
                if ($operation === 'review-list') {
                    $service->templates(TemplateLibraryScope::Review);
                } else {
                    $service->reviewTemplate($template->id, 1, 'approve');
                }
                self::fail('Ordinary Communications access approved institutional wording.');
            } catch (AccessDeniedException) {
                self::assertSame('template_review', $service->show($template->id)['status']);
            }
        }
        $f->access->actor = $f->user();
        $f->access->grants[] = 'sis:communications:admin';
        self::assertCount(1, $service->templates(TemplateLibraryScope::Review)['items']);
        self::assertSame('Reviewed fictional body.', $service->show($template->id)['body']);
        $service->reviewTemplate($template->id, 1, 'approve');
        $reader = $f->user();
        $f->access->actor = $reader;
        $f->access->grants = ['sis:communications:manage'];
        self::assertCount(1, $service->templates(TemplateLibraryScope::Institutional)['items']);
        self::assertFalse($service->show($template->id)['is_owner']);
        $draft = $service->draftFromTemplate($template->id, 2);
        self::assertSame($reader->value, $draft->ownerUserId->value);
        foreach (['edit', 'retire'] as $operation) {
            try {
                if ($operation === 'edit') {
                    $service->edit($template->id, 'Denied', 'Denied text.', 2);
                } else {
                    $service->retireTemplate($template->id, 2, true);
                }
                self::fail('Shared reading granted template management.');
            } catch (AccessDeniedException) {
                self::assertSame(2, $service->show($template->id)['version']);
            }
        }
        $f->access->actor = $author;
        $service->reviewTemplate($template->id, 2, 'withdraw');
        $service->edit($template->id, 'New private wording', 'Not approved for sharing.', 3);
        $f->access->actor = $reader;
        self::assertSame([], $service->templates(TemplateLibraryScope::Institutional)['items']);
        self::assertSame('Reviewed fictional body.', $service->show($draft->id)['body']);
        try {
            $service->show($template->id);
            self::fail('Withdrawn wording remained shared.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_deliveries'));
        }
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testStaleApprovalCannotPublishAReplacementSubmissionAndReturnsNeedNewApproval(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Fictional template', 'First wording.');
        $service->reviewTemplate($template->id, 0, 'submit');
        try {
            $service->edit($template->id, 'Unauthorized edit', 'Unreviewed.', 1);
            self::fail('Submitted wording was edited in place.');
        } catch (DomainException) {
            self::assertSame(1, $service->show($template->id)['version']);
        }
        $service->reviewTemplate($template->id, 1, 'withdraw');
        $service->edit($template->id, 'New wording', 'Second wording.', 2);
        $service->reviewTemplate($template->id, 3, 'submit');
        try {
            $service->reviewTemplate($template->id, 1, 'approve');
            self::fail('An earlier approval authorized a newer submission.');
        } catch (ConcurrentModificationException) {
            self::assertSame('template_review', $service->show($template->id)['status']);
        }
        $service->reviewTemplate($template->id, 4, 'return');
        self::assertSame('template', $service->show($template->id)['status']);
        self::assertSame([], $service->templates(TemplateLibraryScope::Institutional)['items']);
        $service->reviewTemplate($template->id, 5, 'submit');
        $service->reviewTemplate($template->id, 6, 'approve');
        $service->retireTemplate($template->id, 7, true);
        $service->retireTemplate($template->id, 8, false);
        self::assertSame('template', $service->show($template->id)['status']);
        self::assertSame([], $service->templates(TemplateLibraryScope::Institutional)['items']);
    }

    public function testApprovalRollsBackWhenReviewerAuthorityChangesDuringItsAudit(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Fictional template', 'Fictional body.');
        $service->reviewTemplate($template->id, 0, 'submit');
        $before = $f->sql->rows('sis_campaigns');
        $auditBefore = $f->sql->rows('sis_audit');
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(
            static function (EntityId $actor, string $action, EntityId $id) use ($f): void {
                $f->audit->record($actor, $action, $id);
                $f->access->staff = false;
                $f->access->grants = ['sis:communications:manage'];
            }
        );
        try {
            $this->service($f, audit: $audit)->reviewTemplate($template->id, 1, 'approve');
            self::fail('An approval committed after administration access was lost.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows('sis_campaigns'));
            self::assertSame($auditBefore, $f->sql->rows('sis_audit'));
        }
    }

    public function testReviewerWorklistRechecksItsIndependentPermissionAfterReading(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Fictional template', 'Fictional body.');
        $service->reviewTemplate($template->id, 0, 'submit');
        $real = new SqlCampaignRepository($f->sql);
        $repo = $this->createStub(CampaignRepository::class);
        $repo->method('templates')->willReturnCallback(static function () use ($f, $real): array {
            $rows = $real->templates($f->access->actor, TemplateLibraryScope::Review);
            $f->access->staff = false;
            $f->access->grants = ['sis:communications:manage'];
            return $rows;
        });
        $this->expectException(AccessDeniedException::class);
        $this->service($f, repository: $repo)->templates(TemplateLibraryScope::Review);
    }

    public function testSharedContentIsNotReturnedWhenVisibilityChangesDuringDecryption(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $template = $service->createTemplate('Fictional shared template', 'Fictional body.');
        $service->reviewTemplate($template->id, 0, 'submit');
        $service->reviewTemplate($template->id, 1, 'approve');
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['sis:communications:manage'];
        $cipher = $this->createStub(MessageCipher::class);
        $cipher->method('open')->willReturnCallback(static function (string $value) use ($f, $template): string {
            $plain = $f->dropCipher->open($value);
            $f->sql->update('sis_campaigns', ['status' => 'template'], ['id' => $template->id->value]);
            return $plain;
        });
        $this->expectException(AccessDeniedException::class);
        $this->service($f, cipher: $cipher)->show($template->id);
    }

    public function testInstitutionalPagesFilterBeforeTheirBoundAndNeverIncludeUnapprovedWording(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        for ($i = 0; $i < 23; ++$i) {
            $template = $service->createTemplate('Approved wording ' . $i, 'Reviewed fictional body.');
            $service->reviewTemplate($template->id, 0, 'submit');
            $service->reviewTemplate($template->id, 1, 'approve');
        }
        $service->createTemplate('Private secret', 'Unapproved private text.');
        $pending = $service->createTemplate('Pending secret', 'Unapproved pending text.');
        $service->reviewTemplate($pending->id, 0, 'submit');
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['sis:communications:manage'];
        $first = $service->templates(TemplateLibraryScope::Institutional);
        self::assertCount(20, $first['items']);
        self::assertNotNull($first['next_after']);
        $second = $service->templates(TemplateLibraryScope::Institutional, new EntityId($first['next_after']));
        self::assertCount(3, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(23, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        self::assertStringNotContainsString('secret', json_encode([$first, $second], JSON_THROW_ON_ERROR));
        self::assertSame([], $service->templates(TemplateLibraryScope::Submitted)['items']);
    }
}
