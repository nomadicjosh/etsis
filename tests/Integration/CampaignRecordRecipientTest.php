<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Communications\Enum\RecordRecipientSource;
use Domain\Communications\Repository\CampaignRepository;
use Domain\Communications\Service\CampaignService;
use Domain\Communications\Service\MailTransport;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCampaignRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CampaignRecordRecipientTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $f, ?CampaignRepository $repository = null): CampaignService
    {
        $transport = $this->createMock(MailTransport::class);
        $transport->expects(self::never())->method('send');
        return new CampaignService(
            $repository ?? new SqlCampaignRepository($f->sql),
            $f->dropCipher,
            $transport,
            $f->access,
            $f->transactions,
            $f->audit
        );
    }

    private function fixture(): array
    {
        $f = new SisFixture();
        $student = $f->student();
        $person = $f->admissions->person($student->personId);
        $application = $f->legacyApplication($person->id, $f->program, $f->term);
        $f->sql->update(
            'users',
            ['email' => 'fictional-reader@example.invalid'],
            ['user_id' => $person->ownerUserId->value]
        );
        return [$f, $person, $student, $application];
    }

    private function facts(SisFixture $f): array
    {
        return array_map($f->sql->rows(...), ['sis_persons', 'users', 'sis_campaigns', 'sis_deliveries',
            'sis_campaign_dispatches', 'sis_history_events', 'sis_history_streams', 'sis_audit']);
    }

    public function testAllSourceRecordsResolveOneCanonicalAccountWithoutSideEffectsOrEmailExposure(): void
    {
        [$f, $person, $student, $application] = $this->fixture();
        $before = $this->facts($f);
        $service = $this->service($f);
        $sources = [['person', $person->id], ['student', $student->id], ['application', $application->id]];
        foreach ($sources as [$source, $id]) {
            $items = $service->recordRecipient(RecordRecipientSource::from($source), $id)['items'];
            self::assertCount(1, $items);
            self::assertSame($person->ownerUserId->value, $items[0]['user_id']);
            self::assertSame($person->id->value, $items[0]['id']);
            self::assertSame(['id', 'user_id', 'given_name', 'family_name', 'institutional_id'], array_keys($items[0]));
            self::assertStringNotContainsString('@example.invalid', json_encode($items, JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->facts($f));
    }

    public function testUnknownDisabledUnlinkedAndInvalidEmailSourcesCannotStartARecipientHandoff(): void
    {
        foreach (['missing', 'disabled', 'unlinked', 'invalid_email'] as $problem) {
            [$f, $person] = $this->fixture();
            if ($problem === 'disabled' || $problem === 'invalid_email') {
                $f->sql->update(
                    'users',
                    $problem === 'disabled' ? ['sign_in_enabled' => 0] : ['email' => 'invalid'],
                    ['user_id' => $person->ownerUserId->value]
                );
            }
            if ($problem === 'unlinked') {
                $f->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $person->id->value]);
            }
            $before = $this->facts($f);
            try {
                $this->service($f)->recordRecipient(
                    RecordRecipientSource::Person,
                    $problem === 'missing' ? EntityId::generate() : $person->id
                );
                self::fail('An unavailable canonical account was selected.');
            } catch (ValidationException $error) {
                self::assertSame('recipient', $error->errors[0]['field']);
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    public function testSourceDutyAndCommunicationsAuthorityAreRequiredBeforeAndAfterLookup(): void
    {
        foreach (RecordRecipientSource::cases() as $source) {
            $requirements = array_unique([
                'sis:communications:manage', 'sis:person:manage', $source->permission(), 'actor',
            ]);
            foreach ($requirements as $lost) {
                [$f, $person, $student, $application] = $this->fixture();
                $ids = ['person' => $person->id, 'student' => $student->id, 'application' => $application->id];
                $real = new SqlCampaignRepository($f->sql);
                $other = $f->user();
                $repository = $this->createMock(CampaignRepository::class);
                $repository->expects(self::once())->method('recordAudience')->willReturnCallback(
                    static function (
                        RecordRecipientSource $kind,
                        EntityId $id
                    ) use (
                        $f,
                        $real,
                        $source,
                        $lost,
                        $other
                    ): array {
                        $row = $real->recordAudience($kind, $id);
                        $f->access->staff = false;
                        $f->access->grants = array_values(array_diff(
                            ['sis:communications:manage', 'sis:person:manage', $source->permission()],
                            [$lost]
                        ));
                        if ($lost === 'actor') {
                            $f->access->actor = $other;
                        }
                        return $row;
                    }
                );
                $before = $this->facts($f);
                try {
                    $this->service($f, $repository)->recordRecipient($source, $ids[$source->value]);
                    self::fail('Lookup survived revoked source authority or a changed actor.');
                } catch (AccessDeniedException) {
                    self::assertSame($before, $this->facts($f));
                }
                if ($lost !== 'actor') {
                    $repository->expects(self::never())->method('audience');
                    try {
                        $this->service($f, $repository)->recordRecipient($source, $ids[$source->value]);
                        self::fail('Lookup began without the required authority.');
                    } catch (AccessDeniedException) {
                        self::assertSame($before, $this->facts($f));
                    }
                }
            }
        }
    }

    public function testConnectedDraftUsesTheExistingReviewedQueueAndRechecksTheAccount(): void
    {
        [$f, $person, , $application] = $this->fixture();
        $service = $this->service($f);
        $recipient = new EntityId($service->recordRecipient(
            RecordRecipientSource::Application,
            $application->id
        )['items'][0]['user_id']);
        $draft = $service->create('Your application', 'Sign in to review your next steps.');
        $review = $service->reviewRecipients($draft->id, [$recipient], 0);
        self::assertSame($recipient->value, $review['recipients'][0]['user_id']);
        self::assertSame([], $f->sql->rows('sis_deliveries'));
        $f->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $person->ownerUserId->value]);
        try {
            $service->queue($draft->id, [$recipient], 0);
            self::fail('A disabled account entered the delivery queue.');
        } catch (\DomainException) {
            self::assertSame([], $f->sql->rows('sis_deliveries'));
        }
        $f->sql->update('users', ['sign_in_enabled' => 1], ['user_id' => $person->ownerUserId->value]);
        self::assertCount(1, $service->queue($draft->id, [$recipient], 0));
        self::assertCount(1, $f->sql->rows('sis_campaigns'));
        self::assertCount(1, $f->sql->rows('sis_deliveries'));
    }
}
