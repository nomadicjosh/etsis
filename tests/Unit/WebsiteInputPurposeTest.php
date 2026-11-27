<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Exception\ValidationException;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Domain\WebsiteForms\ValueObject\WebsiteInputPurpose;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebsiteInputPurposeTest extends TestCase
{
    public static function supportedFields(): array
    {
        return [['text', 'given-name'], ['text', 'postal-code'], ['email', 'email'], ['tel', 'tel'],
            ['url', 'url'], ['textarea', 'street-address'], ['select', 'country-name']];
    }

    #[DataProvider('supportedFields')]
    public function testExplicitPurposeSurvivesNormalizationWithoutChangingAnswerMeaning(
        string $type,
        string $purpose,
    ): void {
        $facts = WebsiteFormDefinition::starter();
        $facts['fields'] = [['name' => 'contact', 'type' => $type, 'label' => 'Fictional contact detail',
            'required' => true, 'input_purpose' => $purpose]];
        $answer = match ($type) {
            'email' => 'fictional@example.invalid', 'url' => 'https://example.invalid',
            'tel' => '+1 202 555 0100', default => 'Fictional value',
        };
        if ($type === 'select') {
            $facts['fields'][0]['options'] = [['value' => $answer, 'label' => 'Fictional country']];
        }
        $definition = new WebsiteFormDefinition($facts);
        self::assertSame($purpose, $definition->facts['fields'][0]['input_purpose']);
        self::assertSame(['contact' => $answer], $definition->answers(['contact' => $answer]));
        self::assertSame($definition->facts, new WebsiteFormDefinition($definition->facts)->facts);
    }

    public static function unsafePurposes(): array
    {
        return [['text', 'email'], ['number', 'postal-code'], ['checkbox', 'name'], ['radio', 'name'],
            ['textarea', 'given-name'], ['select', 'name'], ['date', 'name'], ['text', 'current-password'],
            ['text', 'cc-number'], ['text', 'on'], ['text', 'off'], ['text', 'billing name'],
            ['text', 'section-other name'], ['text', 'NAME'], ['text', ''], ['text', true], ['text', 1],
            ['text', ['name']], ['text', 'name" autofocus onfocus="alert(1)']];
    }

    #[DataProvider('unsafePurposes')]
    public function testForgedOrIncompatiblePurposesCannotReachPublication(string $type, mixed $purpose): void
    {
        $facts = WebsiteFormDefinition::starter();
        $facts['fields'][0]['type'] = $type;
        $facts['fields'][0]['input_purpose'] = $purpose;
        if (in_array($type, ['select', 'radio'], true)) {
            $facts['fields'][0]['options'] = [['value' => 'one', 'label' => 'First']];
        }
        $this->expectException(ValidationException::class);
        new WebsiteFormDefinition($facts);
    }

    public function testEarlierOmittedPurposesRemainOmittedAndExplicitNullMeansUnspecified(): void
    {
        $facts = WebsiteFormDefinition::starter();
        unset($facts['fields'][0]['input_purpose'], $facts['fields'][1]['input_purpose']);
        $earlier = new WebsiteFormDefinition($facts);
        foreach ($earlier->facts['fields'] as $field) {
            self::assertArrayNotHasKey('input_purpose', $field);
        }
        self::assertSame($earlier->facts, new WebsiteFormDefinition($earlier->facts)->facts);
        $facts['fields'][0]['input_purpose'] = null;
        self::assertNull(new WebsiteFormDefinition($facts)->facts['fields'][0]['input_purpose']);
    }

    public function testDesignerChoicesUseTheSameAcceptedPurposeAndTypeContracts(): void
    {
        $choices = WebsiteInputPurpose::choices();
        self::assertCount(22, $choices);
        self::assertCount(22, array_unique(array_column($choices, 'value')));
        foreach ($choices as $choice) {
            self::assertNotSame('', $choice['label']);
            foreach ($choice['types'] as $type) {
                self::assertTrue(WebsiteInputPurpose::allows($type, $choice['value']));
            }
        }
    }
}
