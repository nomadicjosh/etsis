<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Event\AdmissionDecisionWasObserved;
use Domain\Admissions\Event\AdmissionOfferWasRecorded;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdmissionOfferHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    public function testReplayRejectsRewrittenLettersAndResponsesUnderAnotherAdmissionDecision(): void
    {
        foreach (['letter', 'owner', 'policy', 'decision', 'version', 'previous', 'channel'] as $change) {
            $f = new SisFixture();
            $person = $f->legacyPerson($f->access->actor, 'Fictional', 'History applicant');
            $a = $f->legacyApplication($person->id, $f->program, $f->term)->decide(true);
            $root = AdmissionDecision::root($id = new AdmissionDecisionId($a->id->value));
            $context = EntityId::generate()->value;
            $root->acceptHistorical(AdmissionDecisionWasObserved::occur($id, [
                'decision_id' => $context, 'application' => AdmissionDecisionSchema::facts($a),
                'source' => $f->applicationContext()->source($a),
            ], ['__aggregate_type' => 'admission_decision'])->withPlayhead(1));
            $operation = EntityId::generate()->value;
            $p = ['operation_id' => $operation, 'previous_operation_id' => null,
                'admission_context_id' => $context, 'application_version' => 2, 'action' => 'issue',
                'channel' => 'staff', 'offer' => ['id' => $operation, 'owner_id' => $f->access->actor->value,
                    'title' => 'Fictional letter', 'message' => 'Original fictional terms.', 'respond_by' => null,
                    'require_acceptance' => true, 'policy_revision' => 0, 'status' => 'pending']];
            $root->acceptHistorical(
                AdmissionOfferWasRecorded::occur($id, $p, ['__aggregate_type' => 'admission_decision'])
                        ->withPlayhead(2)
            );
            self::assertFalse($root->acceptanceKnown());
            $p['previous_operation_id'] = $operation;
            $p['operation_id'] = EntityId::generate()->value;
            $p['application_version'] = 3;
            $p['action'] = 'accept';
            $p['channel'] = 'applicant';
            $p['offer']['status'] = 'accepted';
            match ($change) {
                'letter' => $p['offer']['message'] = 'Rewritten fictional terms.',
                'owner' => $p['offer']['owner_id'] = EntityId::generate()->value,
                'policy' => $p['offer']['require_acceptance'] = false,
                'decision' => $p['admission_context_id'] = EntityId::generate()->value,
                'version' => $p['application_version'] = 4,
                'previous' => $p['previous_operation_id'] = null,
                'channel' => $p['channel'] = 'unregistered',
            };
            try {
                $root->acceptHistorical(
                    AdmissionOfferWasRecorded::occur($id, $p, ['__aggregate_type' => 'admission_decision'])
                        ->withPlayhead(3)
                );
                self::fail('Replay accepted altered terms or a broken admission reference.');
            } catch (CorruptHistoryException) {
                self::assertSame('Original fictional terms.', $root->offer()['offer']['message']);
                self::assertSame('pending', $root->offer()['offer']['status']);
            }
        }
    }
}
