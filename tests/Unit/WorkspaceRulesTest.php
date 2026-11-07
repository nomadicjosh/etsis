<?php

declare(strict_types=1);

use Domain\Admissions\ApplicationForm;
use Domain\Rules\RuleSet;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class WorkspaceRulesTest extends TestCase
{
    public function testNestedRulesNormalizeBooleanAndNumericValues(): void
    {
        $rules = new RuleSet(['condition' => 'AND', 'rules' => [
            ['id' => 'active', 'operator' => 'equal', 'value' => 'true'],
            ['condition' => 'OR', 'rules' => [
                ['id' => 'credits', 'operator' => 'greater', 'value' => '10'],
                ['id' => 'program', 'operator' => 'in', 'value' => ['CS', 'ART']],
            ]],
        ]], ['active' => 'boolean', 'credits' => 'integer', 'program' => 'string']);
        self::assertTrue($rules->matches(['active' => true, 'credits' => 12, 'program' => 'MATH']));
        self::assertFalse($rules->matches(['active' => false, 'credits' => 12, 'program' => 'CS']));
        self::assertFalse($rules->matches(['active' => true, 'credits' => 9, 'program' => 'MATH']));
    }

    public function testRulesRejectUnauthorizedFieldsAndExecutableOperators(): void
    {
        foreach ([['email', 'equal'], ['program', 'raw_sql']] as [$field, $operator]) {
            try {
                new RuleSet(['condition' => 'AND', 'rules' => [
                    ['id' => $field, 'operator' => $operator, 'value' => 'anything'],
                ]], ['program' => 'string']);
                self::fail('An unapproved rule was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $group = ['condition' => 'AND', 'rules' => [['id' => 'program', 'operator' => 'equal', 'value' => 'CS']]];
        for ($depth = 0; $depth < 6; ++$depth) {
            $group = ['condition' => 'AND', 'rules' => [$group]];
        }
        $this->expectException(InvalidArgumentException::class);
        new RuleSet($group, ['program' => 'string']);
    }

    public function testHiddenAnswersAreDiscardedAndForwardReferencesRejected(): void
    {
        $condition = ['condition' => 'AND', 'rules' => [
            ['id' => 'transfer', 'operator' => 'equal', 'value' => true],
        ]];
        $fields = [
            ['key' => 'transfer', 'label' => 'Transfer?', 'type' => 'checkbox'],
            ['key' => 'college', 'label' => 'College', 'type' => 'text', 'required' => true, 'show_when' => $condition],
        ];
        $form = new ApplicationForm(EntityId::generate(), EntityId::generate(), 'Application', $fields);
        self::assertSame(['transfer' => false], $form->validate(['transfer' => false, 'college' => 'Hidden value']));
        $this->expectException(InvalidArgumentException::class);
        new ApplicationForm(EntityId::generate(), EntityId::generate(), 'Application', array_reverse($fields));
    }
}
