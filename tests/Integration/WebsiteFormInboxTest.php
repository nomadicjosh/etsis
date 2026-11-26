<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteFormFixture;

final class WebsiteFormInboxTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFormFixture.php';
    }

    public function testPrivateIntakeCanBeReadClosedReopenedAndPermanentlyDeletedWithoutCreatingPeopleOrEvents(): void
    {
        $f = new WebsiteFormFixture();
        $s = $f->sis;
        $before = array_map($s->sql->rows(...), ['sis_history_events', 'sis_persons', 'sis_applications']);
        $proof = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $result = $f->submit($proof);
        self::assertTrue($result['received']);
        self::assertSame($result, $f->submit($proof));
        $list = $f->inbox->page();
        self::assertCount(1, $list['items']);
        self::assertStringNotContainsString('alex@example.invalid', json_encode($list));
        self::assertStringNotContainsString('private inquiry', json_encode($s->sql->rows('sis_website_form_inbox')));
        $id = new EntityId($list['items'][0]['id']);
        $record = $f->inbox->show($id);
        self::assertSame($f->answers, $record['answers']);
        $f->inbox->change($id, 0, 'close');
        self::assertSame('closed', $f->inbox->show($id)['status']);
        $f->inbox->change($id, 1, 'reopen');
        self::assertSame('open', $f->inbox->show($id)['status']);
        $f->inbox->change($id, 2, 'delete');
        self::assertSame([], $s->sql->rows('sis_website_form_inbox'));
        self::assertSame($result, $f->submit($proof));
        self::assertSame([], $s->sql->rows('sis_website_form_inbox'));
        self::assertSame($before, array_map($s->sql->rows(...), ['sis_history_events', 'sis_persons',
            'sis_applications']));
    }

    public function testAlteredProofSessionAnswersAndHoneypotAreRejectedWithoutPartialRecords(): void
    {
        $f = new WebsiteFormFixture();
        $proof = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        foreach (
            [['bad-csrf', $proof['ticket'], ''], ['fictional-browser-csrf', 'bad-ticket', ''],
            ['fictional-browser-csrf', $proof['ticket'], 'bot-filled']] as [$csrf, $ticket, $trap]
        ) {
            try {
                $f->submissions->submit('1', 'contact', $csrf, $ticket, $f->answers, $trap);
                self::fail('A forged or automated submission was accepted.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sis->sql->rows('sis_website_form_inbox'));
            }
        }
        $f->submit($proof);
        $this->expectException(ConcurrentModificationException::class);
        $f->submit($proof, array_replace($f->answers, ['message' => 'Changed private content.']));
    }

    public function testActualPublicationChangesAndExpiryStopFreshIntakeButAnOriginalRetryRemainsExact(): void
    {
        $f = new WebsiteFormFixture();
        $proof = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $accepted = $f->submit($proof);
        $stale = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $next = WebsiteFormDefinition::starter();
        $next['title'] = 'A changed contact form';
        $f->definition($next);
        try {
            $f->submit($stale);
            self::fail('An edited page accepted a fresh submission.');
        } catch (RecordNotFoundException) {
            self::assertCount(1, $f->sis->sql->rows('sis_website_form_inbox'));
        }
        self::assertSame($accepted, $f->submit($proof));
        $f->website->transition('1', 'submit');
        $f->website->transition('1', 'approve');
        $expired = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        $this->expectException(ConcurrentModificationException::class);
        $f->submit($expired);
    }

    public function testContentAndReceiptRemovalFollowSeparateWorkingRetentionBoundaries(): void
    {
        $f = new WebsiteFormFixture();
        $proof = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $f->submit($proof);
        $id = new EntityId($f->inbox->page()['items'][0]['id']);
        $f->inbox->change($id, 0, 'delete');
        $f->sis->clock->time = $f->sis->clock->time->modify('+2 hours');
        try {
            $f->submit($proof);
            self::fail('Expired deleted content was recreated.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sis->sql->rows('sis_website_form_inbox'));
        }
    }

    public function testServerValidationAndIndependentInboxDutiesCannotBeBypassed(): void
    {
        $f = new WebsiteFormFixture();
        try {
            $f->submit(answers: []);
            self::fail('Missing required answers were accepted.');
        } catch (ValidationException) {
            self::assertSame([], $f->sis->sql->rows('sis_website_form_inbox'));
        }
        $f->submit();
        $id = new EntityId($f->inbox->page()['items'][0]['id']);
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['vihzhuo:manage'];
        try {
            $f->inbox->show($id);
            self::fail('Website editing granted inquiry read.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->sis->access->grants[] = 'sis:website:forms:read';
        self::assertSame($f->answers, $f->inbox->show($id)['answers']);
        $this->expectException(AccessDeniedException::class);
        $f->inbox->change($id, 0, 'delete');
    }

    public function testAuditAndFinalAuthorityFailuresRollBackInquiryChanges(): void
    {
        foreach (['audit', 'authority'] as $failure) {
            $f = new WebsiteFormFixture();
            $f->submit();
            $id = new EntityId($f->inbox->page()['items'][0]['id']);
            $before = $f->sis->sql->rows('sis_website_form_inbox');
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($failure, $f): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
                $f->sis->access->staff = false;
                $f->sis->access->grants = [];
            });
            try {
                $f->inbox($audit)->change($id, 0, 'delete');
                self::fail('A failed required audit/duty committed.');
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame($before, $f->sis->sql->rows('sis_website_form_inbox'));
            }
        }
    }

    public function testCiphertextCannotBeMovedToAnotherInquiry(): void
    {
        $f = new WebsiteFormFixture();
        $f->submit();
        $f->submit();
        $rows = $f->sis->sql->rows('sis_website_form_inbox');
        $f->sis->sql->update(
            'sis_website_form_inbox',
            ['sealed_data' => $rows[0]['sealed_data']],
            ['id' => $rows[1]['id']]
        );
        $this->expectException(\Domain\WebsiteForms\Exception\UnreadableInquiryException::class);
        $f->inbox->show(new EntityId($rows[1]['id']));
    }
    public function testRateLimitAndBoundedReceiptPruningKeepInquiryContentsSeparate(): void
    {
        $f = new WebsiteFormFixture();
        for ($i = 0; $i < 20; $i++) {
            $f->submit();
        }
        try {
            $f->submit();
            self::fail('The browser/page hourly limit was bypassed.');
        } catch (ValidationException) {
            self::assertCount(20, $f->sis->sql->rows('sis_website_form_inbox'));
            self::assertCount(20, $f->sis->sql->rows('sis_website_form_receipts'));
        }
        $f->sis->clock->time = $f->sis->clock->time->modify('+2 hours');
        self::assertSame(1, $f->records->prune(
            $f->sis->clock->time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            1
        ));
        self::assertCount(19, $f->sis->sql->rows('sis_website_form_receipts'));
        self::assertCount(20, $f->sis->sql->rows('sis_website_form_inbox'));
        $f->submit();
        self::assertCount(21, $f->sis->sql->rows('sis_website_form_inbox'));
        self::assertNotNull($f->inbox->page()['next_after']);
    }
}
