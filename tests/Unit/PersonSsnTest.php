<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Person\PersonSsnHistory;
use Domain\Person\ValueObject\SocialSecurityNumber;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class PersonSsnTest extends TestCase
{
    public function testFormattingAndSerializationNeverExposeThePrivateValue(): void
    {
        $number = new SocialSecurityNumber(' 000-00-1234 ');
        self::assertSame('000001234', $number->digits());
        self::assertSame('1234', $number->lastFour());
        self::assertSame(['ssn' => '[protected]'], $number->__debugInfo());
        self::assertSame('{"ssn":"[protected]"}', json_encode($number));
    }

    public function testOnlyExplicitNineDigitInputsAreAcceptedWithoutReflectingInvalidValues(): void
    {
        foreach (['', '1234', '***-**-1234', '000 00 1234', '000-001234', '0000012340', '１２３４５６７８９'] as $bad) {
            try {
                new SocialSecurityNumber($bad);
                self::fail('An invalid SSN input was accepted.');
            } catch (ValidationException $error) {
                self::assertSame('ssn', $error->errors[0]['field']);
            }
        }
        self::assertSame('Verified correction.', PersonSsnHistory::reason(' Verified correction. '));
    }
}
