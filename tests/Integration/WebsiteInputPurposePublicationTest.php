<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\WebsiteForms\Service\WebsiteFormCatalog;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteFormFixture;

final class WebsiteInputPurposePublicationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFormFixture.php';
    }

    public function testPurposesRequirePublicationAndEarlierAcceptedInquiriesKeepTheirDefinition(): void
    {
        $f = new WebsiteFormFixture();
        $earlier = WebsiteFormDefinition::starter();
        unset($earlier['fields'][0]['input_purpose'], $earlier['fields'][1]['input_purpose']);
        $f->definition($earlier);
        $f->website->transition('1', 'submit');
        $f->website->transition('1', 'approve');
        $ticket = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $accepted = $f->submit($ticket);
        $original = $f->sis->sql->rows('sis_website_form_inbox');
        $draft = $earlier;
        $draft['fields'][0]['input_purpose'] = 'given-name';
        $f->definition($draft);
        $catalog = new WebsiteFormCatalog($f->website->pages);
        try {
            $catalog->resolve('1', 'contact', 'en');
            self::fail('A changed form bypassed publication.');
        } catch (RecordNotFoundException) {
            self::assertSame($accepted, $f->submit($ticket));
        }
        $body = json_decode($f->sis->dropCipher->open($original[0]['sealed_data']), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('input_purpose', $body['definition']['fields'][0]);
        self::assertSame($f->answers, $body['answers']);
        $f->website->transition('1', 'submit');
        $f->website->transition('1', 'approve');
        self::assertSame(
            'given-name',
            $catalog->resolve('1', 'contact', 'en')['definition']->facts['fields'][0]['input_purpose']
        );
        self::assertSame($accepted, $f->submit($ticket));
        self::assertSame($original, $f->sis->sql->rows('sis_website_form_inbox'));
    }

    public function testAnUnacceptedTicketCannotSurviveAPurposeOnlyPublicationChange(): void
    {
        $f = new WebsiteFormFixture();
        $ticket = $f->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        $draft = WebsiteFormDefinition::starter();
        $draft['fields'][0]['input_purpose'] = 'family-name';
        $f->definition($draft);
        $f->website->transition('1', 'submit');
        $f->website->transition('1', 'approve');
        try {
            $f->submit($ticket);
            self::fail('An earlier ticket accepted the changed form.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sis->sql->rows('sis_website_form_inbox'));
            self::assertSame([], $f->sis->sql->rows('sis_website_form_receipts'));
        }
        self::assertTrue($f->submit()['received']);
    }

    public function testIncompatiblePurposeBlocksPublicationWithoutAnyNewIntakeOrAcademicFacts(): void
    {
        $f = new WebsiteFormFixture();
        $history = $f->sis->sql->rows('sis_history_events');
        $draft = WebsiteFormDefinition::starter();
        $draft['fields'][0]['input_purpose'] = 'current-password';
        $f->definition($draft);
        try {
            $f->website->transition('1', 'submit');
            self::fail('An unsupported purpose entered publication review.');
        } catch (ValidationException) {
            self::assertSame([], $f->sis->sql->rows('sis_website_form_inbox'));
            self::assertSame($history, $f->sis->sql->rows('sis_history_events'));
        }
    }
}
