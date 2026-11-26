<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Exception\ValidationException;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteFormDefinitionTest extends TestCase
{
    public function testContactFieldsAreIndependentAndStrictlyValidated(): void
    {
        $definition = new WebsiteFormDefinition(WebsiteFormDefinition::starter());
        self::assertSame(['name', 'email', 'message'], array_column($definition->facts['fields'], 'name'));
        self::assertSame(
            ['name' => 'Alex', 'email' => 'alex@example.invalid', 'message' => 'Fictional <script>text</script>'],
            $definition->answers(['name' => ' Alex ', 'email' => 'alex@example.invalid',
                'message' => 'Fictional <script>text</script>'])
        );
        foreach (
            [[], ['name' => 'Alex', 'email' => 'bad', 'message' => 'Hello'],
            ['name' => 'Alex', 'email' => 'alex@example.invalid', 'message' => 'Hello',
                'student_id' => 'private']] as $invalid
        ) {
            try {
                $definition->answers($invalid);
                self::fail('Unconfigured or invalid answers were accepted.');
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors);
                self::assertStringNotContainsString('private', json_encode($error->errors));
            }
        }
    }

    public function testConditionalQuestionsDiscardNoHiddenAnswersSilently(): void
    {
        $facts = WebsiteFormDefinition::starter();
        $facts['fields'] = [
            ['name' => 'reply', 'type' => 'checkbox', 'label' => 'Request a reply', 'required' => false],
            ['name' => 'email', 'type' => 'email', 'label' => 'Reply address', 'required' => true,
                'show_when' => ['field' => 'reply', 'value' => true]],
        ];
        $definition = new WebsiteFormDefinition($facts);
        self::assertSame(['reply' => false], $definition->answers([]));
        self::assertSame(
            ['reply' => true, 'email' => 'alex@example.invalid'],
            $definition->answers(['reply' => true, 'email' => 'alex@example.invalid'])
        );
        $this->expectException(ValidationException::class);
        $definition->answers(['reply' => false, 'email' => 'secret@example.invalid']);
    }

    public function testChoiceValuesAreStableAndMultiChoiceInputsCannotContainObjects(): void
    {
        $facts = WebsiteFormDefinition::starter();
        $facts['fields'] = [['name' => 'interests', 'type' => 'multi_select', 'label' => 'Interests',
            'required' => true, 'options' => [['value' => 'a', 'label' => 'First'], ['value' => 'b',
                'label' => 'Second']]]];
        $definition = new WebsiteFormDefinition($facts);
        self::assertSame(['interests' => ['b', 'a']], $definition->answers(['interests' => ['b', 'a']]));
        foreach ([[], ['a', 'a'], ['foreign'], [['nested' => 'private']], [true], 'a'] as $invalid) {
            try {
                $definition->answers(['interests' => $invalid]);
                self::fail('Invalid choices were accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCharacterNumericAndDateBoundsUseActualValues(): void
    {
        $facts = WebsiteFormDefinition::starter();
        $facts['fields'] = [
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true,
                'min_length' => 2, 'max_length' => 3],
            ['name' => 'quantity', 'type' => 'number', 'label' => 'Quantity', 'required' => true, 'min' => 0,
                'max' => 3],
            ['name' => 'date', 'type' => 'date', 'label' => 'Date', 'required' => true,
                'min' => '2028-02-28', 'max' => '2028-02-29'],
        ];
        $definition = new WebsiteFormDefinition($facts);
        self::assertSame('éé', $definition->answers(['message' => 'éé', 'quantity' => '0',
            'date' => '2028-02-29'])['message']);
        foreach (
            [['message' => 'a', 'quantity' => '0', 'date' => '2028-02-29'],
            ['message' => 'abcd', 'quantity' => '4', 'date' => '2028-03-01'],
            ['message' => 'abc', 'quantity' => '1.2', 'date' => '2028-02-30']] as $invalid
        ) {
            try {
                $definition->answers($invalid);
                self::fail('Bounds were bypassed.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public static function invalidDefinitions(): array
    {
        return [['empty'], ['duplicate'], ['unknown_type'], ['duplicate_options'], ['later_condition'],
            ['reversed_length'], ['reversed_number'], ['bad_date'], ['too_many'], ['non_boolean']];
    }

    #[DataProvider('invalidDefinitions')]
    public function testMalformedDefinitionsCannotBePublishedOrUsed(string $case): void
    {
        $facts = WebsiteFormDefinition::starter();
        switch ($case) {
            case 'empty':
                $facts['fields'] = [];
                break;
            case 'duplicate':
                $facts['fields'][1]['name'] = 'name';
                break;
            case 'unknown_type':
                $facts['fields'][0]['type'] = 'file';
                break;
            case 'duplicate_options':
                $facts['fields'][0]['type'] = 'select';
                $facts['fields'][0]['options'] = [['value' => 'x', 'label' => 'First'], ['value' => 'x',
                    'label' => 'Second']];
                break;
            case 'later_condition':
                $facts['fields'][0]['show_when'] = ['field' => 'email', 'value' => 'a'];
                break;
            case 'reversed_length':
                $facts['fields'][0] += ['min_length' => 3, 'max_length' => 2];
                break;
            case 'reversed_number':
                $facts['fields'][0]['type'] = 'number';
                $facts['fields'][0] += ['min' => 3, 'max' => 2];
                break;
            case 'bad_date':
                $facts['fields'][0]['type'] = 'date';
                $facts['fields'][0]['min'] = '2028-02-30';
                break;
            case 'too_many':
                $facts['fields'] = array_fill(0, 41, $facts['fields'][0]);
                break;
            case 'non_boolean':
                $facts['fields'][0]['required'] = 'false';
                break;
        }
        $this->expectException(ValidationException::class);
        new WebsiteFormDefinition($facts);
    }
}
