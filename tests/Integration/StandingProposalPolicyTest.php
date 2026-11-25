<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\StandingProposalFixture;

final class StandingProposalPolicyTest extends TestCase
{
    private function fixture(): StandingProposalFixture
    {
        require_once dirname(__DIR__) . '/Support/StandingProposalFixture.php';
        return new StandingProposalFixture(false);
    }

    public function testAcceptedPolicyRetryKeepsOriginalResultAfterLaterPublicationAndRetirement(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $service = $s->standingPolicies();
        $review = $service->review($f->rules, 0, 'Fictional original policy.');
        $operation = new EntityId($review['publication_id']);
        $receipt = $service->publish($operation, $review['rules'], 0, $review['reason'], $review['review_token']);
        $f->rules['enabled'] = false;
        $f->publish();
        $s->standingService()->saveDefinition(
            new EntityId($f->definitions[1]['id']),
            'GOOD',
            'Fictional retired label',
            false,
            $f->definitions[1]['version']
        );
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $f->facts();
        self::assertSame($receipt, $service->publish(
            $operation,
            $review['rules'],
            0,
            $review['reason'],
            $review['review_token']
        ));
        self::assertSame($before, $f->facts());
    }

    public static function failures(): array
    {
        return [['definition'], ['authority'], ['expiry'], ['audit'], ['counter']];
    }

    #[DataProvider('failures')]
    public function testPublicationFailuresAfterRequiredAuditPreservePolicyHistoryAndRevision(string $failure): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $before = $f->facts();
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(function () use ($f, $s, $failure): void {
            match ($failure) {
                'definition' => $s->standingService()->saveDefinition(
                    new EntityId($f->definitions[1]['id']),
                    'GOOD', 'Fictional changed policy label', false, $f->definitions[1]['version']
                ),
                'authority' => $s->access->staff = false,
                'expiry' => $s->clock->time = $s->clock->time->modify('+21 minutes'),
                'audit' => throw new \RuntimeException('Fictional policy audit failure.'),
                'counter' => $s->sql->update(
                    'sis_standing_proposal_policy_state',
                    ['version' => 1],
                    ['id' => \Domain\Student\StandingProposalPolicy::ID]
                ),
            };
        });
        try {
            $f->publish($audit);
            self::fail('A failed policy publication committed.');
        } catch (
            \Domain\Shared\Exception\ValidationException | \Domain\Shared\Exception\AccessDeniedException
            | \Domain\Shared\Exception\ConcurrentModificationException | \RuntimeException
        ) {
            self::assertSame($before, $f->facts());
        }
    }
}
