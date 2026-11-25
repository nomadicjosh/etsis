<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\StandingScopeFixture;

final class StandingPolicyScopeTest extends TestCase
{
    private function fixture(bool $assign = true): StandingScopeFixture
    {
        require_once dirname(__DIR__) . '/Support/StandingScopeFixture.php';
        return new StandingScopeFixture($assign);
    }

    public static function precedence(): array
    {
        return [['default', 'institution', 'GOOD'], ['level', 'academic_level', 'REVIEW'],
            ['program', 'program', 'GOOD'], ['both', 'program', 'GOOD']];
    }

    #[DataProvider('precedence')]
    public function testActualProgramAndCohortLevelSelectTheMostSpecificPublishedBands(
        string $mode,
        string $kind,
        string $standing,
    ): void {
        $f = $this->fixture();
        $overrides = [];
        if (in_array($mode, ['level', 'both'], true)) {
            $overrides[] = $f->override('academic_level', $f->undergraduate);
        }
        if (in_array($mode, ['program', 'both'], true)) {
            $overrides[] = $f->override('program', $f->sis->program, 1000);
        }
        $f->publish($overrides, $mode === 'default');
        $before = $f->facts();
        $proposal = $f->propose();
        self::assertSame($kind, $proposal['scope']['kind']);
        self::assertSame($standing, $proposal['standing']['code']);
        self::assertArrayNotHasKey('level_source', $proposal['scope']);
        self::assertArrayNotHasKey('program_id', $proposal['scope']);
        self::assertSame($before, $f->facts());
    }

    public function testAnExplicitProgramRuleDoesNotRequireAnUnusedHistoricalLevel(): void
    {
        $f = $this->fixture(false);
        $f->publish([$f->override('academic_level', $f->undergraduate),
            $f->override('program', $f->sis->program, 1000)], false);
        self::assertSame('program', $f->propose()['scope']['kind']);
    }

    public function testAnUnknownCohortCannotSilentlyFallBackPastLevelRules(): void
    {
        $f = $this->fixture(false);
        $f->publish([$f->override('academic_level', $f->graduate)]);
        $before = $f->facts();
        try {
            $f->propose();
            self::fail('An unknown cohort level silently used institutional defaults.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('verified published program cohort', $e->errors[0]['message']);
        }
        self::assertSame($before, $f->facts());
    }

    public static function fallback(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('fallback')]
    public function testAKnownUnmatchedOrExplicitlyBlankLevelUsesInstitutionalDefaults(bool $blank): void
    {
        $f = $this->fixture();
        if ($blank) {
            $f->publishProgram(null);
        }
        $f->publish([$f->override('academic_level', $f->graduate)]);
        $proposal = $f->propose();
        self::assertSame('institution', $proposal['scope']['kind']);
        self::assertSame('GOOD', $proposal['standing']['code']);
    }

    public static function disabled(): array
    {
        return [['program'], ['academic_level']];
    }

    #[DataProvider('disabled')]
    public function testADisabledSpecificPolicyCannotFallThroughToABroaderEnabledPolicy(string $kind): void
    {
        $f = $this->fixture();
        $target = $kind === 'program' ? $f->sis->program : $f->undergraduate;
        $f->publish([$f->override($kind, $target, enabled: false)]);
        $before = $f->facts();
        try {
            $f->propose();
            self::fail('A disabled applicable override fell through to the enabled default.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('disabled by the policy', $e->errors[0]['message']);
        }
        self::assertSame($before, $f->facts());
    }

    public function testDraftAndUnassignedNewProgramPublicationsDoNotChangeTheAssignedCohortLevel(): void
    {
        $f = $this->fixture();
        $f->publish([$f->override('academic_level', $f->undergraduate),
            $f->override('academic_level', $f->graduate, 1000)]);
        $f->draftLevel($f->graduate);
        self::assertSame('REVIEW', $f->propose()['standing']['code']);
        $f->publishProgram($f->graduate, false);
        self::assertSame('REVIEW', $f->propose()['standing']['code']);
        $f->publishProgram($f->graduate);
        self::assertSame('GOOD', $f->propose()['standing']['code']);
    }

    public function testTheApplicableOverrideControlsMinimumGpaCredits(): void
    {
        $f = $this->fixture();
        $override = $f->override('academic_level', $f->undergraduate);
        $override['rules']['minimum_gpa_credits_milli'] = 6000;
        $f->publish([$override]);
        try {
            $f->propose();
            self::fail('The default minimum bypassed a stricter applicable minimum.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('minimum GPA credits', $e->errors[0]['message']);
        }
        $f->base->work();
        self::assertSame(6000, $f->propose()['gpa_credits_milli']);
    }

    public function testAnAcceptedLevelDecisionRetainsItsOriginalCohortAfterLaterChanges(): void
    {
        $f = $this->fixture();
        $f->publish([$f->override('academic_level', $f->undergraduate)]);
        $proposal = $f->propose();
        $review = $f->accept($proposal);
        $s = $f->sis;
        $f->publishProgram($f->graduate);
        $f->publish([], false);
        $before = $f->facts();
        $last = $s->standingLedger()->latest($f->base->student, $f->base->pursuit);
        $proof = \Domain\Student\StudentStanding::proposalEvidence($last->event);
        self::assertSame($f->programVersion->value, $proof->data['scope']['level_source']['program_version_id']);
        self::assertSame('REVIEW', $proof->band()['code']);
        self::assertSame('UG', $proof->scopeSummary()['code']);
        $receipt = $s->standingService()->commit(
            $f->base->student,
            $f->base->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        );
        self::assertSame('updated', $receipt['status']);
        self::assertSame($before, $f->facts());
    }

    public static function damage(): array
    {
        return [['missing'], ['program'], ['position'], ['level'], ['year'], ['unassigned']];
    }

    #[DataProvider('damage')]
    public function testRetainedScopeMustMatchTheOriginalPolicyPursuitPositionAndAssignedPublication(
        string $damage
    ): void {
        $f = $this->fixture();
        $f->publish([$f->override('academic_level', $f->undergraduate)]);
        $evidence = $f->sis->standingProposals()->calculate(
            $f->base->student,
            $f->base->pursuit,
            EntityId::generate(),
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        );
        switch ($damage) {
            case 'missing':
                unset($evidence['scope']);
                break;
            case 'program':
                $evidence['scope']['program_id'] = EntityId::generate()->value;
                break;
            case 'position':
                $evidence['scope']['pursuit_version']++;
                break;
            case 'level':
                $evidence['scope']['level_source']['academic_level']['title'] = 'Fictional forged label';
                break;
            case 'year':
                $evidence['scope']['level_source']['academic_year_id'] = EntityId::generate()->value;
                break;
            case 'unassigned':
                // A genuine publication with identical classifications still was not this student's cohort.
                $evidence['scope']['level_source']['program_version_id'] =
                $f->publishProgram($f->undergraduate, false)->value;
                break;
        }
        $before = $f->facts();
        try {
            $proof = new \Domain\Student\ValueObject\StandingProposalEvidence($evidence);
            $f->sis->standingPolicies()->verifyEvidence($proof, new \Domain\Shared\EventSourcing\HistoryReadBudget());
            self::fail('A substituted or detached policy scope was authenticated.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testEarlierUnscopedFactsKeepTheirOriginalShapeWithoutInventingScope(): void
    {
        $f = $this->fixture(false);
        $f->base->publish(); // Genuine original three-field policy.
        $evidence = $f->sis->standingProposals()->calculate(
            $f->base->student,
            $f->base->pursuit,
            EntityId::generate(),
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        );
        // Earlier decisions recorded only the original fifteen evidence fields.
        unset($evidence['scope'], $evidence['assessment']);
        $proof = new \Domain\Student\ValueObject\StandingProposalEvidence($evidence);
        $before = $f->facts();
        $summary = $f->sis->standingProposals()->summary($proof, new \Domain\Shared\EventSourcing\HistoryReadBudget());
        self::assertNull($proof->scopeSummary());
        self::assertArrayNotHasKey('scope', $summary);
        self::assertSame('GOOD', $proof->band()['code']);
        self::assertSame($before, $f->facts());
    }

    public static function timing(): array
    {
        return [['review'], ['commit'], ['audit']];
    }

    #[DataProvider('timing')]
    public function testAChangedAssignedCohortRequiresAFreshProposalEvenWhenTheGpaAndBandAreUnchanged(
        string $timing
    ): void {
        $f = $this->fixture();
        $f->publish([$f->override('academic_level', $f->undergraduate),
            $f->override('academic_level', $f->graduate)]);
        $s = $f->sis;
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(fn() => $f->publishProgram($f->graduate));
        $service = $s->standingService($timing === 'audit' ? $audit : null);
        $proposal = $f->propose();
        $view = $service->view($f->base->student, $f->base->pursuit);
        $review = null;
        if ($timing !== 'review') {
            $review = $service->review(
                $f->base->student,
                $f->base->pursuit,
                [$f->base->row($proposal)],
                $view['revision'],
                'Fictional cohort race.',
                proposal: $proposal['proposal']
            );
        }
        if ($timing !== 'audit') {
            $f->publishProgram($f->graduate);
        }
        $before = $f->facts();
        try {
            if ($timing === 'review') {
                $service->review(
                    $f->base->student,
                    $f->base->pursuit,
                    [$f->base->row($proposal)],
                    $view['revision'],
                    'Fictional stale cohort.',
                    proposal: $proposal['proposal']
                );
            } else {
                $service->commit(
                    $f->base->student,
                    $f->base->pursuit,
                    new EntityId($review['operation_id']),
                    $review['rows'],
                    $review['revision'],
                    $review['reason'],
                    $review['review_token'],
                    proposal: $review['proposal']
                );
            }
            self::fail('An outdated cohort scope was accepted.');
        } catch (ValidationException | \Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public static function targetChanges(): array
    {
        return [['program'], ['level'], ['definition']];
    }

    #[DataProvider('targetChanges')]
    public function testScopedPublicationRechecksTargetsAndEnabledOutcomesAfterAuditAndRollsBack(string $change): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $f->base->rules['enabled'] = false;
        $f->base->rules['overrides'] = [$f->override('program', $s->program),
            $f->override('academic_level', $f->undergraduate)];
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(function () use ($s, $f, $change): void {
            match ($change) {
                'program' => $s->sql->update('sis_programs', ['title' => 'Fictional changed program'],
                    ['id' => $s->program->value]),
                'level' => $s->sql->update('sis_catalog_classifications', ['active' => 0],
                    ['id' => $f->undergraduate->value]),
                'definition' => $s->standingService()->saveDefinition(
                    new EntityId($f->base->definitions[0]['id']),
                    'REVIEW',
                    'Fictional retired outcome',
                    false,
                    $f->base->definitions[0]['version']
                ),
            };
        });
        $before = $f->facts();
        try {
            $f->base->publish($audit);
            self::fail('A changed scoped publication committed.');
        } catch (ValidationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testARetiredTargetCanBeExplicitlyDisabledOrRemovedButNotSilentlyReenabled(): void
    {
        $f = $this->fixture();
        $override = $f->override('academic_level', $f->undergraduate);
        $f->publish([$override]);
        $f->sis->sql->update(
            'sis_catalog_classifications',
            ['active' => 0, 'title' => 'Fictional retired level'],
            ['id' => $f->undergraduate->value]
        );
        $override['rules']['enabled'] = false;
        $f->publish([$override]);
        self::assertSame(
            'Fictional undergraduate',
            $f->sis->standingPolicies()->view()['rules']['overrides'][0]['target']['title']
        );
        $override['rules']['enabled'] = true;
        $f->base->rules['overrides'] = [$override];
        try {
            $f->base->publish();
            self::fail('A retired target was reenabled.');
        } catch (ValidationException) {
            $f->publish([]);
            self::assertSame([], $f->sis->standingPolicies()->view()['rules']['overrides']);
        }
    }

    public function testAcceptedScopedPolicyRetriesDoNotDependOnRenamedTargets(): void
    {
        $f = $this->fixture();
        $rules = $f->base->rules + ['overrides' => [$f->override('academic_level', $f->undergraduate)]];
        $service = $f->sis->standingPolicies();
        $review = $service->review($rules, 0, 'Fictional original scoped policy.');
        $publish = fn() => $service->publish(
            new EntityId($review['publication_id']),
            $rules,
            0,
            $review['reason'],
            $review['review_token']
        );
        $original = $publish();
        $f->sis->sql->update(
            'sis_catalog_classifications',
            ['active' => 0, 'title' => 'Fictional retired level'],
            ['id' => $f->undergraduate->value]
        );
        $f->publish([]);
        $f->sis->clock->time = $f->sis->clock->time->modify('+1 day');
        $before = $f->facts();
        self::assertSame($original, $publish());
        self::assertSame($before, $f->facts());
    }


    public function testTargetChoicesAreBoundedTypedAndLiteralSearchableWithoutCatalogManagement(): void
    {
        $f = $this->fixture(false);
        $s = $f->sis;
        $s->sql->update(
            'sis_catalog_classifications',
            ['title' => 'Fictional 50%_! level'],
            ['id' => $f->undergraduate->value]
        );
        $template = $s->sql->one('sis_programs', ['id' => $s->program->value]);
        for ($n = 1; $n <= 24; $n++) {
            $s->sql->insert('sis_programs', array_replace($template, ['id' => EntityId::generate()->value,
                'code' => 'SCOPE.' . $n, 'title' => 'Fictional scope program ' . $n]));
        }
        $s->access->staff = false;
        $s->access->grants = ['sis:standing:configure', 'sis:student:read'];
        $before = $f->facts();
        $service = $s->standingPolicies();
        $first = $service->targets('program', 'scope.');
        self::assertCount(20, $first['items']);
        $second = $service->targets('program', 'scope.', new EntityId($first['next_after']));
        self::assertCount(4, $second['items']);
        self::assertNull($second['next_after']);
        self::assertSame([], array_intersect(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        ));
        self::assertSame(
            [$f->undergraduate->value],
            array_column($service->targets('academic_level', '%_!')['items'], 'id')
        );
        self::assertSame($before, $f->facts());
    }

    public function testWrongKindAndStaleTargetLabelsCannotBePublished(): void
    {
        $f = $this->fixture();
        $override = $f->override('program', $f->sis->program);
        $override['target']['title'] = 'Fictional unreviewed label';
        $f->base->rules['overrides'] = [$override];
        $before = $f->facts();
        try {
            $f->base->publish();
            self::fail('Stale target labels were published.');
        } catch (ValidationException) {
            self::assertSame($before, $f->facts());
        }
        $repo = new \Infrastructure\Persistence\Repository\SqlStandingPolicyTargetRepository($f->sis->sql);
        $this->expectException(\Domain\Shared\Exception\RecordNotFoundException::class);
        $repo->get('academic_level', $f->sis->program);
    }
}
