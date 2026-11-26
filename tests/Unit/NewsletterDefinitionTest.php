<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\Exception\ValidationException;
use Domain\WebsiteForms\ValueObject\NewsletterConnection;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewsletterDefinitionTest extends TestCase
{
    private function facts(): array
    {
        $facts = WebsiteFormDefinition::starter();
        $facts['fields'][] = ['name' => 'newsletter', 'type' => 'checkbox',
            'label' => 'Fictional newsletter consent', 'required' => false];
        $facts['newsletter'] = ['connection_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'target_hash' => str_repeat('a', 64),
            'email_field' => 'email', 'name_field' => null, 'consent_field' => 'newsletter'];
        return $facts;
    }

    public function testOrdinaryFormsHaveNoConnectionAndConsentStaysUncheckedAndOptional(): void
    {
        self::assertNull(new WebsiteFormDefinition(WebsiteFormDefinition::starter())->facts['newsletter']);
        $form = new WebsiteFormDefinition($this->facts());
        $answers = $form->answers(['name' => 'Fictional Alex', 'email' => 'fictional@example.invalid',
            'message' => 'Fictional contact message']);
        self::assertFalse($answers['newsletter']);
        self::assertSame('newsletter', $form->facts['newsletter']['consent_field']);
    }

    #[DataProvider('invalidBindings')]
    public function testRequiredConditionalMissingAndUnrelatedConsentMappingsAreRejected(string $mode): void
    {
        $facts = $this->facts();
        match ($mode) {
            'required_consent' => $facts['fields'][3]['required'] = true,
            'conditional_consent' => $facts['fields'][3]['show_when'] = ['field' => 'name', 'value' => 'Fictional'],
            'optional_email' => $facts['fields'][1]['required'] = false,
            'text_consent' => $facts['newsletter']['consent_field'] = 'message',
            'missing_consent' => $facts['newsletter']['consent_field'] = 'missing',
            'invalid_target' => $facts['newsletter']['target_hash'] = 'bad',
            'unapproved_field' => $facts['newsletter']['extra'] = 'message',
        };
        $this->expectException(ValidationException::class);
        new WebsiteFormDefinition($facts);
    }

    public static function invalidBindings(): array
    {
        return array_map(static fn(string $key): array => [$key], ['required_consent', 'conditional_consent',
            'optional_email', 'text_consent', 'missing_consent', 'invalid_target', 'unapproved_field']);
    }

    public function testUnsafeDestinationUrlsAndWeakWebhookKeysAreRejected(): void
    {
        foreach (
            ['http://newsletter.example.invalid', 'https://user:pass@newsletter.example.invalid',
            'https://newsletter.example.invalid/?key=unsafe', 'https://newsletter.example.invalid/../private'] as $url
        ) {
            try {
                new NewsletterConnection(
                    new \Domain\Shared\ValueObject\EntityId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
                    'sendy',
                    $url,
                    'fictional_list',
                    'fictional-api-key'
                );
                self::fail('Unsafe connection URL accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ValidationException::class);
        new NewsletterConnection(
            new \Domain\Shared\ValueObject\EntityId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
            'webhook',
            'https://newsletter.example.invalid',
            'fictional_list',
            'short'
        );
    }
    public function testWebhookDestinationKeepsItsExactTrailingSlashAndDistinctTargetIdentity(): void
    {
        $id = new \Domain\Shared\ValueObject\EntityId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $first = new NewsletterConnection(
            $id,
            'webhook',
            'https://newsletter.example.invalid/hook/',
            'prospects',
            'fictional-signing-secret-at-least-32-characters'
        );
        $second = new NewsletterConnection(
            $id,
            'webhook',
            'https://newsletter.example.invalid/hook',
            'prospects',
            'fictional-signing-secret-at-least-32-characters'
        );
        self::assertSame('https://newsletter.example.invalid/hook/', $first->url);
        self::assertNotSame($first->targetHash, $second->targetHash);
    }
}
