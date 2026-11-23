<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Service\AdmissionDecisionLedger;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlSubmissionProjection;
use Infrastructure\Projection\SqlApplicationProjectionVerifier;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ApplicationProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function application(SisFixture $s, bool $tracked = true): AdmissionApplication
    {
        $person = $s->legacyPerson($s->user(), 'Fictional', 'Applicant');
        return $tracked
        ? $s->submissionService()->apply(EntityId::generate(), $person->id, $s->program, $s->term)
        : $s->legacyApplication($person->id, $s->program, $s->term);
    }

    private function verifier(SisFixture $s, ?HistoricalEventStore $history = null): SqlApplicationProjectionVerifier
    {
        $ledger = $history === null ? $s->decisionLedger() : new AdmissionDecisionLedger(
            $history,
            $s->access,
            $s->clock,
            $s->applicationContext(),
            new SqlSubmissionProjection($s->sql),
        );
        return new SqlApplicationProjectionVerifier($s->sql, $s->admissions, $ledger);
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_applications',
            'sis_application_intake', 'sis_form_submissions', 'sis_application_revisions',
            'sis_students', 'sis_student_programs', 'sis_persons', 'users', 'sis_audit',
        ]);
    }

    public function testTrackedApplicationVerifiesWithWritesProhibitedAndMinimizedOutput(): void
    {
        $s = new SisFixture();
        $application = $this->application($s);
        $before = $this->facts($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($s)->verify($application->id);
        self::assertSame('verified', $result->status);
        self::assertSame(1, $result->events);
        self::assertSame(1, $result->decisionPosition);
        self::assertSame(0, $result->matriculationPosition);
        self::assertFalse($result->earlierHistoryUnknown);
        self::assertSame($before, $this->facts($s));
        $json = json_encode($result->describe(), JSON_THROW_ON_ERROR);
        foreach (['Fictional', 'Applicant', 'submitted', 'answers', 'encrypted', 'sealed', 'password'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testActualDecisionAndMatriculationShareOneExactEventAllowance(): void
    {
        $s = new SisFixture();
        $application = $this->application($s);
        $review = $s->decisionService()->review($application->id, true, 'Fictional private reason');
        $s->decisionService()->commit($application->id, $review['review_token']);
        $s->matriculate($application->id);
        $before = $this->facts($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($s)->verify($application->id, 3);
        self::assertSame('verified', $result->status);
        self::assertSame(3, $result->events);
        self::assertSame(2, $result->decisionPosition);
        self::assertSame(1, $result->matriculationPosition);
        self::assertSame('limited', $this->verifier($s)->verify($application->id, 2)->status);
        self::assertSame($before, $this->facts($s));
    }

    public function testUntrackedAndObservedEarlierApplicationsRemainExplicitHistoryGaps(): void
    {
        $s = new SisFixture();
        $application = $this->application($s, false);
        $before = $this->facts($s);
        $result = $this->verifier($s)->verify($application->id);
        self::assertSame('history_gaps', $result->status);
        self::assertSame(0, $result->events);
        self::assertTrue($result->earlierHistoryUnknown);
        self::assertSame($before, $this->facts($s));
        $review = $s->decisionService()->review($application->id, true, 'Fictional acceptance');
        $s->decisionService()->commit($application->id, $review['review_token']);
        $s->matriculate($application->id);
        $before = $this->facts($s);
        $result = $this->verifier($s)->verify($application->id);
        self::assertSame('history_gaps', $result->status);
        self::assertSame(3, $result->events);
        self::assertTrue($result->earlierHistoryUnknown);
        self::assertSame($before, $this->facts($s));
    }

    public function testApplicationProjectionDriftIsNotAdoptedOrRepaired(): void
    {
        $changes = ['status' => 'accepted', 'version' => 1, 'entry_term_id' => '00000000000000000000000000'];
        foreach ($changes as $key => $value) {
            $s = new SisFixture();
            $application = $this->application($s);
            if ($key === 'entry_term_id') {
                $term = $s->sql->rows('sis_terms', ['id' => $s->term->value], 1)[0];
                $term['id'] = $value;
                $term['code'] = 'FICTIONAL-OTHER';
                $s->sql->insert('sis_terms', $term);
            }
            $s->sql->update('sis_applications', [$key => $value], ['id' => $application->id->value]);
            $before = $this->facts($s);
            $result = $this->verifier($s)->verify($application->id);
            self::assertSame('unverified', $result->status);
            self::assertSame(['application'], $result->issues);
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testMissingOrAlteredIntakeRowsAreReportedWithoutFillingKnownEarlierValues(): void
    {
        foreach (['missing', 'channel', 'submitted_term_code', 'submitted_at'] as $change) {
            $s = new SisFixture();
            $application = $this->application($s);
            if ($change === 'missing') {
                $s->sql->execute('DELETE FROM sis_application_intake WHERE id = ?', [$application->id->value]);
            } else {
                $s->sql->update('sis_application_intake', [$change => 'Fictional changed value'], [
                    'id' => $application->id->value,
                ]);
            }
            $before = $this->facts($s);
            $result = $this->verifier($s)->verify($application->id);
            self::assertSame('unverified', $result->status);
            self::assertSame(['intake'], $result->issues);
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testDamagedHistoryNeverExposesProtectedPayloadOrProviderMessages(): void
    {
        $s = new SisFixture();
        $application = $this->application($s);
        $head = $s->sql->rows('sis_history_streams', ['aggregate_id' => $application->id->value], 1)[0];
        self::assertSame(1, $s->sql->update('sis_history_events', [
            'sealed_event' => 'Fictional private damaged payload',
        ], ['stream_id' => $head['id']]));
        $before = $this->facts($s);
        $result = $this->verifier($s)->verify($application->id);
        self::assertSame('unverified', $result->status);
        self::assertStringNotContainsString('Fictional private', json_encode($result->describe()));
        self::assertSame($before, $this->facts($s));
    }

    public function testSourceFailuresAreMinimizedAndAnEnclosingSnapshotIsRejected(): void
    {
        $s = new SisFixture();
        $application = $this->application($s);
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willThrowException(new \RuntimeException('Fictional private provider failure'));
        $result = $this->verifier($s, $history)->verify($application->id);
        self::assertSame('unverified', $result->status);
        self::assertSame(['unavailable'], $result->issues);
        self::assertStringNotContainsString('Fictional private', json_encode($result->describe()));
        $inside = $s->transactions->run(fn () => $this->verifier($s)->verify($application->id));
        self::assertSame('unverified', $inside->status);
        self::assertSame(0, $inside->events);
    }

    public function testConcurrentCommitIsAnIncompleteChangedObservation(): void
    {
        $s = new SisFixture();
        $application = $this->application($s);
        $actual = EventStoreFixture::history($s->connection->queryBuilder());
        $history = $this->createStub(HistoricalEventStore::class);
        $read = function ($stream, $cursor, $limit) use ($actual, $s, $application) {
            $page = $actual->page($stream, $cursor, $limit);
            $s->sql->update('sis_applications', ['version' => 1], ['id' => $application->id->value]);
            return $page;
        };
        $history->method('page')->willReturnCallback($read);
        $result = $this->verifier($s, $history)->verify($application->id);
        self::assertSame('changed', $result->status);
        self::assertSame([], $result->issues);
        self::assertNull($result->earlierHistoryUnknown);
    }

    public function testInvalidBoundsAreRejectedBeforeStorage(): void
    {
        $s = new SisFixture();
        foreach ([0, 2001] as $limit) {
            try {
                $this->verifier($s)->verify(EntityId::generate(), $limit);
                self::fail('An unbounded application check was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $s->sql->rows('sis_applications'));
            }
        }
    }

    public function testRetainedFormAnswersAreVerifiedWithoutBeingExposedOrRewritten(): void
    {
        $s = new SisFixture();
        $person = $s->legacyPerson($s->access->actor, 'Fictional', 'Applicant');
        $forms = new \Domain\Admissions\Service\ApplicationFormService(
            new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository($s->sql),
            $s->admissionsService(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->submissionService(),
        );
        $form = $forms->save(null, $s->program, 'Fictional form', [
            ['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true],
        ], null);
        $forms->publish($form->id, 0);
        $s->access->staff = false;
        $application = $forms->submit(EntityId::generate(), $form->id, $person->id, $s->term, [
            'statement' => 'Fictional private statement',
        ]);
        $before = $this->facts($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($s)->verify($application);
        self::assertSame('verified', $result->status);
        self::assertSame($before, $this->facts($s));
        self::assertStringNotContainsString('Fictional', json_encode($result->describe()));
        $s->connection->pdo->exec('PRAGMA query_only = OFF');
        $s->access->staff = true;
        $revisions = $s->applicationRevisionService();
        $request = $revisions->review($application, 'request', ['instructions' => 'Fictional clarification'], false);
        $revisions->commit($application, $request['review_token'], false);
        $s->access->staff = false;
        $revision = $revisions->review($application, 'submit', [
            'answers' => ['statement' => 'Fictional revised statement'], 'reason' => 'Fictional clarification',
        ], true);
        $revisions->commit($application, $revision['review_token'], true);
        $before = $this->facts($s);
        self::assertSame('verified', $this->verifier($s)->verify($application)->status);
        self::assertSame($before, $this->facts($s));
        $artifact = $s->sql->rows('sis_application_revisions', ['application_id' => $application->value], 1)[0];
        $s->sql->update('sis_application_revisions', ['sealed_data' => 'Fictional damaged revision'], [
            'id' => $artifact['id'],
        ]);
        self::assertSame('unverified', $this->verifier($s)->verify($application)->status);
        $s->sql->update('sis_application_revisions', ['sealed_data' => $artifact['sealed_data']], [
            'id' => $artifact['id'],
        ]);
        self::assertSame(1, $s->sql->update('sis_form_submissions', [
            'encrypted_answers' => 'Fictional damaged private answers',
        ], ['application_id' => $application->value]));
        $before = $this->facts($s);
        $result = $this->verifier($s)->verify($application);
        self::assertSame('unverified', $result->status);
        self::assertSame($before, $this->facts($s));
        self::assertStringNotContainsString('Fictional', json_encode($result->describe()));
    }
}
