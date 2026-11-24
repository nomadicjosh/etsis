<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\SpecializationPolicyService;
use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository;
use Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository;
use PHPUnit\Framework\TestCase;

final class ComponentExclusionsTest extends TestCase
{
    private function fixture(?\Closure $configure = null): \StudentSpecializationFixture
    {
        require_once dirname(__DIR__) . '/Support/StudentSpecializationFixture.php';
        return new \StudentSpecializationFixture(configure: $configure);
    }

    public function testRetainedExclusionsBlockBothDirectionsAndLaterDraftsCannotChangeTheSelectionPolicy(): void
    {
        $f = $this->fixture(static function (\StudentSpecializationFixture $f): void {
            $f->policies()->saveProgram($f->sis->program, 1, 0, 2, componentExclusions: [
                [$f->choices[0]['id'], $f->choices[1]['id']],
            ]);
        });
        $ids = array_column($f->choices, 'id');
        $original = $f->sis->sql->rows('sis_history_events');
        foreach ([[$ids[0], $ids[1]], [$ids[1], $ids[0]]] as $bad) {
            try {
                $f->review($bad);
                self::fail('An incompatible pair was reviewed.');
            } catch (ValidationException $e) {
                self::assertSame('component_exclusions', $e->errors[0]['field']);
            }
        }
        self::assertSame($original, $f->sis->sql->rows('sis_history_events'));
        $first = $f->review([$ids[0]]);
        $receipt = $f->service->commit($f->student, $f->assignment, $first['review_token']);
        $f->select([$ids[1]]);
        $f->policies()->saveProgram($f->sis->program, 2, 0, 2, componentExclusions: []);
        $f->publish();
        self::assertSame($receipt, $f->service->commit($f->student, $f->assignment, $first['review_token']));
        $view = $f->service->view($f->student, $f->assignment);
        self::assertSame([$ids[1]], array_column($view['selection']['specializations'], 'id'));
        self::assertCount(1, $view['program']['specializations']['component_exclusions']);
        $this->expectException(ValidationException::class);
        $f->review([$ids[0], $ids[1]]);
    }

    public function testLaterExclusionsDoNotRewriteEarlierAcceptedChoicesOrMissingEarlierPolicyFields(): void
    {
        $f = $this->fixture();
        $ids = array_column($f->choices, 'id');
        $f->select([$ids[0], $ids[1]]);
        $before = $f->sis->sql->rows('sis_history_events');
        $f->policies()->saveProgram($f->sis->program, 1, 0, 2, componentExclusions: [[$ids[0], $ids[1]]]);
        $f->publish();
        $view = $f->service->view($f->student, $f->assignment);
        self::assertSame([], $view['program']['specializations']['component_exclusions']);
        self::assertSame($ids[0], $view['selection']['specializations'][0]['id']);
        self::assertCount(2, $view['selection']['specializations']);
        self::assertSame($before, array_slice($f->sis->sql->rows('sis_history_events'), 0, count($before)));
        $earlier = $view['program']['specializations'];
        unset($earlier['component_exclusions']);
        self::assertArrayNotHasKey('component_exclusions', new ProgramSpecializationSnapshot($earlier)->data);
    }

    public function testImpossiblePublicationForeignPairsAndChangedReviewsFailWithoutPublishing(): void
    {
        $f = $this->fixture();
        $ids = array_column($f->choices, 'id');
        $before = $f->sis->sql->rows('sis_history_events');
        try {
            $f->policies()->saveProgram(
                $f->sis->program,
                1,
                0,
                2,
                componentExclusions: [[$ids[0], EntityId::generate()->value]]
            );
            self::fail('A foreign or missing component was allowed.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $review = $f->program->service->review($f->sis->program, new EntityId($f->year['id']), 'Fictional review.');
        $f->policies()->saveProgram($f->sis->program, 1, 2, 2, componentExclusions: [
            [$ids[0], $ids[1]], [$ids[0], $ids[2]], [$ids[1], $ids[2]],
        ]);
        try {
            $f->program->service->publish($f->sis->program, $review['review_token']);
            self::fail('A changed program review was accepted.');
        } catch (ConcurrentModificationException | ValidationException) {
            self::assertTrue(true);
        }
        try {
            $f->publish();
            self::fail('An impossible two-of-three policy was published.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
    }

    public function testRequiredAuditSourceAndAuthorityChangesRollbackTheDraftPolicy(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $before = $s->sql->rows('sis_specialization_policies');
        foreach (['audit', 'source', 'authority'] as $mode) {
            $audit = new class ($f, $mode) implements AuditTrail {
                public function __construct(private \StudentSpecializationFixture $fixture, private string $mode)
                {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->mode === 'audit') {
                        throw new \RuntimeException('Fictional audit failure.');
                    }
                    if ($this->mode === 'source') {
                        $this->fixture->sis->sql->update(
                            'sis_program_specializations',
                            ['title' => 'Changed'],
                            ['id' => $this->fixture->choices[0]['id']]
                        );
                    } else {
                        $this->fixture->sis->access->staff = false;
                        $this->fixture->sis->access->grants = [];
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            $service = new SpecializationPolicyService(
                new SqlSpecializationPolicyRepository($s->sql),
                new SqlProgramSpecializationRepository($s->sql),
                new SqlCatalogRevisionRepository($s->sql),
                $s->access,
                $s->transactions,
                $audit
            );
            try {
                $service->saveProgram(
                    $s->program,
                    1,
                    0,
                    2,
                    componentExclusions: [[$f->choices[0]['id'], $f->choices[1]['id']]]
                );
                self::fail('An invalidated policy was saved.');
            } catch (
                \RuntimeException | \Domain\Shared\Exception\AccessDeniedException | ConcurrentModificationException
            ) {
                self::assertSame($before, $s->sql->rows('sis_specialization_policies'));
                $s->access->staff = true;
                self::assertSame('Fictional ART', $f->choiceService()->get(
                    $s->program,
                    new EntityId($f->choices[0]['id'])
                )['title']);
            } finally {
                $s->access->staff = true;
            }
        }
    }
}
