<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CampusFixture.php';

use Domain\Finance\ValueObject\AccountId;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CampusPortalTest extends TestCase
{
    public function testProgramsAndPublishedNewsCanBeReadBeyondTheirFirstBoundedPage(): void
    {
        $c = new CampusFixture();
        for ($index = 0; $index < 104; $index++) {
            $c->sis->sql->insert('sis_programs', ['id' => EntityId::generate()->value,
                'code' => 'P.' . $index, 'title' => 'Fictional program ' . $index]);
        }
        $first = $c->service->programs();
        self::assertCount(100, $first['items']);
        $second = $c->service->programs('', new EntityId($first['next_after']));
        self::assertCount(5, $second['items']);
        self::assertCount(1, $c->service->programs('P.103')['items']);
        self::assertCount(1, $c->service->programs($second['items'][0]['id'])['items']);
        self::assertSame([], $c->service->programs('%')['items']);
        for ($index = 0; $index < 25; $index++) {
            $id = $c->content->save(null, 'news', 'fixture-' . $index, 'News ' . $index, 'Fictional content', 'public', null);
            $c->posts->publish($id, true, 0);
        }
        $news = $c->service->news();
        self::assertCount(24, $news);
        self::assertCount(1, $c->service->news(false, new EntityId($news[23]['id'])));
    }

    public function testPublicNewsRequiresExplicitAudienceAndPublicationAndEditsReturnToDraft(): void
    {
        $c = new CampusFixture();
        $public = $c->content->save(null, 'news', 'public-news', 'Welcome', 'Fictional public update', 'public', null);
        $campus = $c->content->save(null, 'news', 'campus-news', 'Campus', 'Fictional campus update', 'campus', null);
        $old = $c->posts->create('news', 'legacy-news', 'Existing content', 'Campus content stays private.');
        self::assertSame([], $c->service->publicData()['news']);
        foreach ([$public, $campus, $old->id] as $id) { $c->posts->publish($id, true, 0); }
        self::assertSame(['public-news'], array_column($c->service->publicData()['news'], 'code'));
        self::assertCount(3, $c->repository->news(true));
        self::assertSame('Welcome', $c->service->article('public-news')['title']);
        try { $c->service->article('campus-news'); self::fail('Private article escaped its audience.'); }
        catch (RecordNotFoundException) { self::assertTrue(true); }
        $c->content->save($public, 'news', 'public-news', 'Updated title', 'An edited draft', 'public', 1);
        self::assertSame([], $c->service->publicData()['news']);
        self::assertSame(2, $c->content->get($public)['version']);
        $c->posts->publish($public, true, 2);
        self::assertSame('Updated title', $c->service->article('public-news')['title']);
        $this->expectException(ConcurrentModificationException::class);
        $c->content->save($public, 'news', 'public-news', 'Stale edit', 'Old draft', 'campus', 1);
    }

    public function testContentOwnershipAndPublicationPermissionAreEnforced(): void
    {
        $c = new CampusFixture();
        $id = $c->content->save(null, 'news', 'owned-news', 'A title', 'A story', 'campus', null);
        $c->sis->access->actor = $c->sis->user();
        $c->sis->access->staff = false;
        $c->sis->access->grants = ['sis:portal:manage'];
        $this->expectException(AccessDeniedException::class);
        $c->content->get($id);
    }

    public function testPublicCatalogPreservesPunctuationAndDoesNotExposeStudentOrFacultyIdentity(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $section = $f->section();
        $f->sql->update('sis_courses', ['code' => 'MATH.101', 'title' => 'Algebra'], ['id' => $section->courseId->value]);
        $f->sql->update('sis_terms', ['code' => '26/FA'], ['id' => $f->term->value]);
        $f->service->register($f->student()->id, $section->id);
        $items = $c->service->catalog($f->term, 'math.101')['items'];
        self::assertCount(1, $items);
        self::assertSame('26/FA', $items[0]['term_code']);
        self::assertSame(1, $items[0]['enrolled']);
        foreach (['faculty_user_id', 'student_id', 'person_id', 'owner_user_id'] as $field) {
            self::assertArrayNotHasKey($field, $items[0]);
        }
        self::assertSame([], $c->service->catalog(null, '%')['items']);
        self::assertSame([], $c->service->catalog(null, "' OR 1=1 --")['items']);
    }

    public function testContactDetailsAreEncryptedVersionedAndOwnedEvenWhenAnActorHasStaffAccess(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $student = $f->student();
        $c->service->saveContact('Alex', '+1 555 0100', 'Fictional campus address', 0);
        $stored = $f->sql->one('sis_portal_contacts', ['person_id' => $student->personId->value]);
        self::assertStringNotContainsString('Fictional campus address', $stored['encrypted_contact']);
        $me = $c->service->me();
        self::assertSame('Fictional campus address', $me['contact']['address']);
        self::assertSame(1, $me['contact']['version']);
        self::assertArrayNotHasKey('password', $me['person']);
        try { $c->service->saveContact('Stale', '', '', 0); self::fail('Stale profile update was accepted.'); }
        catch (ConcurrentModificationException) { self::assertSame('Alex', $c->service->me()['contact']['preferred_name']); }
        $f->access->actor = $f->user();
        $f->student();
        self::assertSame('', $c->service->me()['contact']['address']);
        $this->expectException(AccessDeniedException::class);
        $c->service->transcript($student->id);
    }

    public function testPortalNeverUsesStaffOverridesToRegisterOrReadOtherStudents(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $student = $f->student($f->user());
        $f->student();
        $section = $f->section();
        foreach (['register', 'drop', 'scores'] as $operation) {
            try { $c->service->$operation($student->id, $section->id); self::fail('Another student was accessible.'); }
            catch (AccessDeniedException) { self::assertSame([], $f->sql->rows('sis_enrollments')); }
        }
        $own = $f->admissions->student(new EntityId($c->service->me()['students'][0]['id']));
        $f->access->staff = false;
        $c->service->register($own->id, $section->id);
        self::assertCount(1, $c->service->transcript($own->id));
        self::assertCount(1, $c->service->me()['students'][0]['enrollments']);
        $c->service->drop($own->id, $section->id);
        self::assertSame('D', $c->service->me()['students'][0]['enrollments'][0]['status']);
    }

    public function testGradesAndAccountStatementsUseCanonicalOwnedRecords(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $grade = new GradeDefinition(EntityId::generate(), 'A', 4000, true, true);
        $c->gradebook->defineGrade($grade);
        $c->gradebook->finalGrade($student->id, $section->id, $grade->id, 0);
        $account = new AccountId($c->service->me()['accounts'][0]['id']);
        $f->access->staff = false;
        self::assertSame('A', $c->service->transcript($student->id)[0]['grade_code']);
        self::assertSame(3000, $c->service->transcript($student->id)[0]['completed_milli']);
        self::assertSame(10000, $c->service->statement($account)['balance_minor']);
        $f->access->actor = $f->user();
        $f->access->staff = true;
        $f->student();
        $this->expectException(AccessDeniedException::class);
        $c->service->statement($account);
    }

    public function testOfficialTranscriptRequestsAreEncryptedIdempotentAndHaveControlledTransitions(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $student = $f->student();
        $owner = $f->access->actor;
        $id = EntityId::generate();
        $f->access->staff = false;
        $c->service->requestTranscript($id, $student->id, 'electronic', 'records@example.invalid');
        $c->service->requestTranscript($id, $student->id, 'electronic', 'records@example.invalid');
        self::assertCount(1, $f->sql->rows('sis_transcript_requests'));
        self::assertStringNotContainsString('records@example.invalid', $f->sql->one('sis_transcript_requests', ['id' => $id->value])['encrypted_recipient']);
        self::assertSame('records@example.invalid', $c->service->transcriptRequest($id)['recipient']);
        self::assertArrayNotHasKey('encrypted_recipient', $c->service->me()['transcript_requests'][0]);
        $f->access->actor = $f->user();
        try { $c->service->transcriptRequest($id); self::fail('A request leaked to another user.'); }
        catch (AccessDeniedException) { self::assertTrue(true); }
        $f->access->grants = ['sis:transcript:manage'];
        self::assertCount(1, $c->service->transcriptQueue());
        $c->service->transitionTranscript($id, 'ready', 0);
        try { $c->service->transitionTranscript($id, 'fulfilled', 0); self::fail('A stale transition succeeded.'); }
        catch (ConcurrentModificationException) { self::assertSame('ready', $c->service->transcriptRequest($id)['status']); }
        $c->service->transitionTranscript($id, 'fulfilled', 1);
        $f->access->actor = $owner;
        $f->access->grants = [];
        self::assertSame('fulfilled', $c->service->transcriptRequest($id)['status']);
        $this->expectException(DomainException::class);
        $c->service->transitionTranscript($id, 'cancelled', 2);
    }

    public function testOnlyTheOwnerCanCancelAPendingTranscriptRequest(): void
    {
        $c = new CampusFixture();
        $student = $c->sis->student();
        $owner = $c->sis->access->actor;
        $id = EntityId::generate();
        $c->service->requestTranscript($id, $student->id, 'pickup', '');
        $c->sis->access->actor = $c->sis->user();
        try { $c->service->transitionTranscript($id, 'cancelled', 0); self::fail('Staff cancelled another owner request.'); }
        catch (AccessDeniedException) { self::assertTrue(true); }
        $c->sis->access->actor = $owner;
        $c->sis->access->staff = false;
        $c->service->transitionTranscript($id, 'cancelled', 0);
        self::assertSame('cancelled', $c->service->transcriptRequest($id)['status']);
    }
}
